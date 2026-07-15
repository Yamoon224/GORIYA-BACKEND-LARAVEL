<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ConnectionService
{
    public function follow(User $follower, User $target): Connection
    {
        if ($follower->id === $target->id) {
            abort(400, 'Vous ne pouvez pas vous suivre vous-même');
        }

        return Connection::firstOrCreate([
            'follower_id' => $follower->id,
            'following_id' => $target->id,
        ]);
    }

    public function unfollow(User $follower, User $target): void
    {
        Connection::where('follower_id', $follower->id)->where('following_id', $target->id)->delete();
    }

    public function isFollowing(User $follower, User $target): bool
    {
        return Connection::where('follower_id', $follower->id)->where('following_id', $target->id)->exists();
    }

    /**
     * @return array<int, string>  ids des utilisateurs suivis
     */
    public function followingIds(User $user): array
    {
        return Connection::where('follower_id', $user->id)->pluck('following_id')->all();
    }

    public function followers(User $user): Collection
    {
        return User::whereIn('id', Connection::where('following_id', $user->id)->pluck('follower_id'))->get();
    }

    public function following(User $user): Collection
    {
        return User::whereIn('id', $this->followingIds($user))->get();
    }
}
