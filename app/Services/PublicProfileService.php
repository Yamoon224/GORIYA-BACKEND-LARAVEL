<?php

namespace App\Services;

use App\Enums\PitchFormat;
use App\Enums\UserRole;
use App\Http\Resources\PostResource;
use App\Models\Pitch;
use App\Models\Portfolio;
use App\Models\Post;
use App\Models\PublicProfile;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Support\Str;

/**
 * Profil Public GORIYA, sur le modèle du profil LinkedIn.
 *
 * URL : /p/{slug} quand le membre a choisi une URL personnalisée, sinon
 * /p/{uuid} — l'uuid reste valable après coup, les liens déjà partagés ne
 * cassent pas.
 *
 * Qui voit quoi (voir showFor()) :
 *  - le propriétaire connecté : mode « owner » (éditeur), brouillons et
 *    coordonnées compris ;
 *  - un membre connecté : mode « member », comme sur LinkedIn où la
 *    visibilité publique ne concerne que les visiteurs non connectés ;
 *  - un visiteur non connecté : mode « public », seulement si le membre a
 *    rendu son profil public.
 *
 * Les pitchs restent privés par défaut : seuls les pitchs vidéo explicitement
 * marqués is_public par leur propriétaire sont exposés.
 */
class PublicProfileService
{
    /** Segments qui ne peuvent pas devenir une URL personnalisée. */
    private const RESERVED_SLUGS = ['me', 'admin', 'api', 'auth', 'edit', 'profil', 'profile', 'settings', 'goriya'];

    public function __construct(
        private readonly ConnectionService $connectionService,
        private readonly PostService $postService,
    ) {}

    /** Créé sans URL personnalisée : le profil s'ouvre alors par l'uuid. */
    public function getOrCreateForUser(User $user): PublicProfile
    {
        return PublicProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['slug' => null, 'theme' => 'DEFAULT', 'is_public' => false],
        );
    }

    /**
     * @param  array{slug?: string|null, theme?: string, isPublic?: bool, seoMeta?: array<string, mixed>}  $data
     */
    public function update(PublicProfile $profile, array $data): PublicProfile
    {
        $payload = [];

        if (array_key_exists('slug', $data)) {
            $payload['slug'] = $this->customSlug($data['slug'], $profile);
        }
        if (array_key_exists('theme', $data)) {
            $payload['theme'] = $data['theme'];
        }
        if (array_key_exists('isPublic', $data)) {
            $payload['is_public'] = $data['isPublic'];
        }
        if (array_key_exists('seoMeta', $data)) {
            $payload['seo_meta'] = $data['seoMeta'];
        }

        $profile->update($payload);

        return $profile->fresh();
    }

    /**
     * @return array<string, mixed>|null null : introuvable, ou non visible par ce lecteur
     */
    public function showFor(string $ref, ?User $viewer): ?array
    {
        [$user, $profile] = $this->resolve($ref);
        if (! $user) {
            return null;
        }

        $isOwner = $viewer !== null && $viewer->id === $user->id;
        $isPublic = (bool) $profile?->is_public;
        $mode = $isOwner ? 'owner' : ($viewer ? 'member' : 'public');

        if ($mode === 'public' && ! $isPublic) {
            return null;
        }

        $portfolios = Portfolio::where('user_id', $user->id)
            ->when(! $isOwner, fn ($query) => $query->where('status', Portfolio::STATUS_PUBLISHED))
            ->orderByDesc('created_date')
            ->get();

        // Une consultation par quelqu'un d'autre compte comme une vue des
        // portfolios affichés (statistiques de l'éditeur de portfolio).
        if (! $isOwner && $portfolios->isNotEmpty()) {
            Portfolio::whereIn('id', $portfolios->pluck('id'))->increment('views');
        }

        return [
            'ref' => $profile?->slug ?: $user->id,
            'slug' => $profile?->slug,
            'userId' => $user->id,
            'theme' => $profile?->theme?->value ?? 'DEFAULT',
            'isPublic' => $isPublic,
            'viewerMode' => $mode,
            'seoMeta' => $profile?->seo_meta,
            'name' => $user->name,
            'avatar' => MediaUrl::resolve($user->avatar),
            'title' => $user->title,
            'location' => $user->location,
            'bio' => $user->bio,
            // Coordonnées : jamais exposées à un tiers, comme sur LinkedIn où
            // elles ne sont visibles que du membre (ou de ses relations).
            'email' => $isOwner ? $user->email : null,
            'phone' => $isOwner ? $user->phone : null,
            'memberSince' => $user->created_at,
            'stats' => [
                'followers' => $this->connectionService->followers($user)->count(),
                'following' => $this->connectionService->following($user)->count(),
                'posts' => Post::where('user_id', $user->id)->whereNull('community_id')->count(),
            ],
            'isFollowing' => $mode === 'member' ? $this->connectionService->isFollowing($viewer, $user) : null,
            'skills' => $portfolios
                ->where('status', Portfolio::STATUS_PUBLISHED)
                ->flatMap(fn (Portfolio $portfolio) => $portfolio->skills ?? [])
                ->filter(fn ($skill) => is_string($skill) && trim($skill) !== '')
                ->unique()
                ->values()
                ->all(),
            'portfolios' => $portfolios->map(fn (Portfolio $portfolio) => [
                'id' => $portfolio->id,
                'title' => $portfolio->title,
                'description' => $portfolio->description,
                'skills' => $portfolio->skills ?? [],
                'theme' => $portfolio->theme ?? 'default',
                'status' => $portfolio->status ?? Portfolio::STATUS_PUBLISHED,
                'photo' => MediaUrl::resolve($portfolio->photo_path),
                'views' => $portfolio->views,
                'details' => $portfolio->details,
            ])->values()->all(),
            'videoPitches' => Pitch::where('user_id', $user->id)
                ->where('format', PitchFormat::VIDEO)
                ->where('is_public', true)
                ->orderByDesc('created_at')
                ->get(['id', 'type', 'video_path', 'score'])
                ->map(fn (Pitch $pitch) => [
                    'id' => $pitch->id,
                    'type' => $pitch->type,
                    'videoUrl' => MediaUrl::resolve($pitch->video_path),
                    'score' => $pitch->score,
                ])
                ->toArray(),
            'recentPosts' => $this->postService->recentByAuthor($user, $viewer)
                ->map(fn (Post $post) => (new PostResource($post))->resolve())
                ->all(),
        ];
    }

    /**
     * @return array{0: User|null, 1: PublicProfile|null}
     */
    private function resolve(string $ref): array
    {
        $profile = PublicProfile::where('slug', Str::lower($ref))->with('user')->first();
        if ($profile?->user) {
            return [$profile->user, $profile];
        }

        // Identifiant par défaut : l'uuid d'un membre standard.
        if (Str::isUuid($ref)) {
            $user = User::where('id', $ref)->where('role', UserRole::USER)->first();
            if ($user) {
                return [$user, PublicProfile::where('user_id', $user->id)->first()];
            }
        }

        return [null, null];
    }

    /**
     * URL personnalisée : vide = retour à l'identifiant par défaut (uuid).
     * Contrairement à l'ancien slug généré, une URL choisie n'est jamais
     * suffixée en silence — si elle est prise, le membre doit en choisir une autre.
     */
    private function customSlug(?string $slug, PublicProfile $profile): ?string
    {
        $slug = Str::lower(trim((string) $slug));
        if ($slug === '') {
            return null;
        }

        if (in_array($slug, self::RESERVED_SLUGS, true) || Str::isUuid($slug)) {
            abort(400, "Cette URL n'est pas disponible.");
        }

        $prise = PublicProfile::where('slug', $slug)->where('id', '!=', $profile->id)->exists();
        if ($prise) {
            abort(400, 'Cette URL est déjà utilisée par un autre membre.');
        }

        return $slug;
    }
}
