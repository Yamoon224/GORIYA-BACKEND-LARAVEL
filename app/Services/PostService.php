<?php

namespace App\Services;

use App\Models\Community;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Fil d'actualité GORIYA Connect — posts des personnes suivies et des
 * communautés rejointes, voir feedFor().
 */
class PostService
{
    public function __construct(private readonly ConnectionService $connectionService, private readonly CommunityService $communityService) {}

    public function create(User $user, string $content, ?Community $community = null): Post
    {
        if ($community && ! $this->communityService->isMember($community, $user)) {
            abort(403, "Rejoignez la communauté avant d'y publier");
        }

        return Post::create([
            'user_id' => $user->id,
            'community_id' => $community?->id,
            'content' => $content,
        ]);
    }

    /**
     * Posts des personnes suivies + des communautés rejointes + les
     * siens, du plus récent au plus ancien.
     */
    public function feedFor(User $user, int $page, int $limit): LengthAwarePaginator
    {
        $followingIds = $this->connectionService->followingIds($user);
        $communityIds = $this->communityService->communityIdsFor($user);

        $paginator = Post::where(function ($query) use ($user, $followingIds, $communityIds) {
            $query->whereIn('user_id', [...$followingIds, $user->id]);
            if ($communityIds !== []) {
                $query->orWhereIn('community_id', $communityIds);
            }
        })
            ->with(['user', 'community'])
            ->withCount('likes')
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);

        return $paginator;
    }

    public function toggleLike(Post $post, User $user): bool
    {
        $existing = PostLike::where('post_id', $post->id)->where('user_id', $user->id)->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        PostLike::create(['post_id' => $post->id, 'user_id' => $user->id]);

        return true;
    }

    public function delete(Post $post): void
    {
        $post->delete();
    }
}
