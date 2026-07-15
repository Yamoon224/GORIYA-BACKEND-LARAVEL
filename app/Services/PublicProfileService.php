<?php

namespace App\Services;

use App\Enums\PitchFormat;
use App\Models\Pitch;
use App\Models\Portfolio;
use App\Models\PublicProfile;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Profil Public GORIYA — page vitrine (goriya.net/{slug}) agrégeant en
 * lecture le Portfolio et les Pitch vidéo existants, sans dupliquer ces
 * données. Voir PublicProfile pour la note sur l'absence du CV.
 *
 * Portfolio est déjà globalement public dans ce backend (PortfoliosController
 * ::index()/show() n'exigent aucune auth — "vitrine" assumée), donc aucun
 * filtre de visibilité supplémentaire n'est appliqué ici. Pitch, à l'inverse,
 * est un modèle privé par défaut (candidatures) : seuls les pitchs vidéo
 * explicitement marqués is_public=true par leur propriétaire (voir
 * PitchService::toggleVisibility()) sont exposés — publier son profil ne
 * doit pas divulguer tous les pitchs jamais enregistrés.
 */
class PublicProfileService
{
    public function getOrCreateForUser(User $user): PublicProfile
    {
        return PublicProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['slug' => $this->generateUniqueSlug($user->name), 'theme' => 'DEFAULT', 'is_public' => false],
        );
    }

    /**
     * @param  array{slug?: string, theme?: string, isPublic?: bool, seoMeta?: array<string, mixed>}  $data
     */
    public function update(PublicProfile $profile, array $data): PublicProfile
    {
        $payload = [];

        if (array_key_exists('slug', $data) && $data['slug'] !== $profile->slug) {
            $payload['slug'] = $this->generateUniqueSlug($data['slug'], excludeProfileId: $profile->id);
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
     * @return array<string, mixed>|null  null si le profil n'existe pas ou n'est pas publié
     */
    public function showPublic(string $slug): ?array
    {
        $profile = PublicProfile::where('slug', $slug)->where('is_public', true)->with('user')->first();

        if (! $profile) {
            return null;
        }

        $user = $profile->user;

        return [
            'slug' => $profile->slug,
            'theme' => $profile->theme,
            'seoMeta' => $profile->seo_meta,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'portfolios' => Portfolio::where('user_id', $user->id)
                ->orderByDesc('created_date')
                ->get(['title', 'description', 'skills'])
                ->toArray(),
            'videoPitches' => Pitch::where('user_id', $user->id)
                ->where('format', PitchFormat::VIDEO)
                ->where('is_public', true)
                ->orderByDesc('created_at')
                ->get(['id', 'type', 'video_path', 'score'])
                ->map(fn (Pitch $pitch) => [
                    'id' => $pitch->id,
                    'type' => $pitch->type,
                    'videoUrl' => $pitch->video_path,
                    'score' => $pitch->score,
                ])
                ->toArray(),
        ];
    }

    private function generateUniqueSlug(string $source, ?string $excludeProfileId = null): string
    {
        $base = Str::slug($source) ?: 'profil';
        $slug = $base;
        $suffix = 1;

        while (
            PublicProfile::where('slug', $slug)
                ->when($excludeProfileId, fn ($query) => $query->where('id', '!=', $excludeProfileId))
                ->exists()
        ) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
