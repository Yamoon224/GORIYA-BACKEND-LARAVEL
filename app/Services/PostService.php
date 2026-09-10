<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\Post;
use App\Models\PostAttachment;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fil d'actualité GORIYA Connect, sur le modèle de LinkedIn : texte, images ou
 * PDF joints, j'aime, commentaires et republications.
 *
 * Visibilité : un post publié hors communauté est visible de tous les membres
 * connectés (comme un post LinkedIn « Tout le monde ») ; un post de communauté
 * reste réservé à ses membres — voir canView().
 */
class PostService
{
    /** Extension enregistrée par type MIME : jamais celle fournie par le client. */
    public const IMAGE_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public const DOCUMENT_MIME_TYPES = [
        'application/pdf' => 'pdf',
    ];

    public const MAX_IMAGES = 9;

    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly ConnectionService $connectionService, private readonly CommunityService $communityService) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function create(User $user, ?string $content, ?Community $community = null, array $files = []): Post
    {
        if ($community && ! $this->communityService->isMember($community, $user)) {
            abort(403, "Rejoignez la communauté avant d'y publier");
        }

        $content = $this->normalizeContent($content);
        if ($content === null && $files === []) {
            abort(400, 'Écrivez quelque chose ou joignez une image ou un PDF.');
        }

        $attachments = $this->validateAttachments($files);

        return DB::transaction(function () use ($user, $community, $content, $attachments) {
            $post = Post::create([
                'user_id' => $user->id,
                'community_id' => $community?->id,
                'content' => $content,
            ]);

            $this->storeAttachments($post, $attachments);

            return $post;
        });
    }

    /**
     * Republication, avec ou sans commentaire. Republier une republication
     * « nue » revient à republier le post d'origine, comme sur LinkedIn.
     */
    public function repost(User $user, Post $original, ?string $comment): Post
    {
        if ($original->repost_of_id && $original->content === null && $original->repostOf) {
            $original = $original->repostOf;
        }

        if (! $this->canView($user, $original)) {
            abort(404, 'Post introuvable');
        }

        if ($original->community_id !== null) {
            abort(400, 'Une publication de communauté ne peut pas être republiée.');
        }

        $comment = $this->normalizeContent($comment);

        $dejaRepublie = Post::where('user_id', $user->id)
            ->where('repost_of_id', $original->id)
            ->whereNull('content')
            ->exists();
        if ($comment === null && $dejaRepublie) {
            abort(400, 'Vous avez déjà republié cette publication.');
        }

        return Post::create([
            'user_id' => $user->id,
            'repost_of_id' => $original->id,
            'content' => $comment,
        ]);
    }

    /**
     * Posts hors communauté de tous les membres + posts des communautés
     * rejointes, du plus récent au plus ancien.
     */
    public function feedFor(User $user, int $page, int $limit): LengthAwarePaginator
    {
        $communityIds = $this->communityService->communityIdsFor($user);

        $query = Post::where(function (Builder $query) use ($communityIds) {
            $query->whereNull('community_id');
            if ($communityIds !== []) {
                $query->orWhereIn('community_id', $communityIds);
            }
        });

        return $this->withViewerData($query, $user)
            ->orderByDesc('created_at')
            ->paginate(max(1, min($limit, 50)), ['*'], 'page', max(1, $page));
    }

    /**
     * Derniers posts publics d'un membre, pour la section « Activité » de son
     * profil public.
     *
     * @return Collection<int, Post>
     */
    public function recentByAuthor(User $author, ?User $viewer, int $limit = 3)
    {
        $query = Post::where('user_id', $author->id)->whereNull('community_id');

        return $this->withViewerData($query, $viewer)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /** Recharge un post avec tout ce qu'affiche une carte du fil. */
    public function loadForViewer(Post $post, User $viewer): Post
    {
        return $this->withViewerData(Post::whereKey($post->id), $viewer)->firstOrFail();
    }

    public function canView(User $user, Post $post): bool
    {
        if ($post->community_id === null || $post->user_id === $user->id || $user->role === UserRole::ADMIN) {
            return true;
        }

        return $post->community !== null && $this->communityService->isMember($post->community, $user);
    }

    public function toggleLike(Post $post, User $user): bool
    {
        if (! $this->canView($user, $post)) {
            abort(404, 'Post introuvable');
        }

        $existing = PostLike::where('post_id', $post->id)->where('user_id', $user->id)->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        PostLike::create(['post_id' => $post->id, 'user_id' => $user->id]);

        return true;
    }

    /** Commentaires du plus récent au plus ancien. */
    public function comments(User $user, Post $post, int $page, int $limit): LengthAwarePaginator
    {
        if (! $this->canView($user, $post)) {
            abort(404, 'Post introuvable');
        }

        return $post->comments()
            ->with('user.company')
            ->orderByDesc('created_at')
            ->paginate(max(1, min($limit, 50)), ['*'], 'page', max(1, $page));
    }

    public function addComment(User $user, Post $post, string $content): PostComment
    {
        if (! $this->canView($user, $post)) {
            abort(404, 'Post introuvable');
        }

        $content = $this->normalizeContent($content);
        if ($content === null) {
            abort(400, 'Le commentaire est vide.');
        }

        return $post->comments()->create(['user_id' => $user->id, 'content' => $content])->load('user.company');
    }

    /** L'auteur du commentaire, l'auteur du post ou un admin peuvent le retirer. */
    public function deleteComment(User $user, PostComment $comment): void
    {
        $autorise = $comment->user_id === $user->id
            || $comment->post?->user_id === $user->id
            || $user->role === UserRole::ADMIN;

        if (! $autorise) {
            abort(403, 'Vous ne pouvez pas supprimer ce commentaire.');
        }

        $comment->delete();
    }

    public function delete(Post $post): void
    {
        foreach ($post->attachments as $attachment) {
            Storage::disk('public')->delete('posts/'.basename($attachment->path));
        }

        $post->delete();
    }

    /**
     * Relations, compteurs et état propre au lecteur (a aimé / a republié).
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private function withViewerData(Builder $query, ?User $viewer): Builder
    {
        $counters = function ($query) use ($viewer) {
            $query->withCount(['likes', 'comments', 'reposts']);

            if ($viewer) {
                $query->withExists([
                    'likes as liked_by_me' => fn ($q) => $q->where('user_id', $viewer->id),
                    'reposts as reposted_by_me' => fn ($q) => $q->where('user_id', $viewer->id),
                ]);
            }
        };

        // Le post d'origine porte aussi ses compteurs : sur une republication
        // sans commentaire, j'aime / commentaires / republication visent l'original.
        $query->with([
            'user.company',
            'attachments',
            'repostOf' => $counters,
            'repostOf.user.company',
            'repostOf.attachments',
        ]);
        $counters($query);

        return $query;
    }

    private function normalizeContent(?string $content): ?string
    {
        $content = trim((string) $content);

        return $content === '' ? null : $content;
    }

    /**
     * Comme LinkedIn : jusqu'à 9 images, ou un unique PDF publié seul.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{0: UploadedFile, 1: string, 2: string}> [fichier, type, extension]
     */
    private function validateAttachments(array $files): array
    {
        $typed = [];

        foreach (array_values($files) as $file) {
            $mime = (string) $file->getMimeType();

            if (isset(self::IMAGE_MIME_TYPES[$mime])) {
                if ($file->getSize() > self::MAX_IMAGE_BYTES) {
                    abort(400, 'Chaque image doit peser 8 Mo au plus.');
                }
                $typed[] = [$file, PostAttachment::TYPE_IMAGE, self::IMAGE_MIME_TYPES[$mime]];

                continue;
            }

            if (isset(self::DOCUMENT_MIME_TYPES[$mime])) {
                if ($file->getSize() > self::MAX_DOCUMENT_BYTES) {
                    abort(400, 'Le PDF doit peser 10 Mo au plus.');
                }
                $typed[] = [$file, PostAttachment::TYPE_DOCUMENT, self::DOCUMENT_MIME_TYPES[$mime]];

                continue;
            }

            abort(400, 'Format non supporté : joignez des images (JPG, PNG, WebP, GIF) ou un PDF.');
        }

        $documents = count(array_filter($typed, fn (array $item) => $item[1] === PostAttachment::TYPE_DOCUMENT));
        if ($documents > 0 && count($typed) > 1) {
            abort(400, 'Un PDF se publie seul : retirez les autres pièces jointes.');
        }

        if (count($typed) > self::MAX_IMAGES) {
            abort(400, 'Neuf images au maximum par publication.');
        }

        return $typed;
    }

    /**
     * @param  list<array{0: UploadedFile, 1: string, 2: string}>  $attachments
     */
    private function storeAttachments(Post $post, array $attachments): void
    {
        foreach ($attachments as $position => [$file, $type, $extension]) {
            $filename = Str::uuid().'.'.$extension;
            Storage::disk('public')->putFileAs('posts', $file, $filename);

            $post->attachments()->create([
                'type' => $type,
                'path' => "/posts/{$filename}",
                'name' => Str::limit($file->getClientOriginalName() ?: $filename, 250, ''),
                'mime_type' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'position' => $position,
            ]);
        }
    }
}
