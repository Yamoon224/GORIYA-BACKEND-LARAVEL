<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class CommunityService
{
    public function listAll(?string $type = null): Collection
    {
        return Community::when($type, fn ($query) => $query->where('type', $type))
            ->orderBy('name')
            ->withCount('memberships')
            ->get();
    }

    public function find(string $id): ?Community
    {
        return Community::withCount('memberships')->find($id);
    }

    /**
     * @param  array{name: string, description?: string, type: string}  $data
     */
    public function create(array $data): Community
    {
        return Community::create([
            'name' => $data['name'],
            'slug' => $this->generateUniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
        ]);
    }

    public function join(Community $community, User $user): CommunityMembership
    {
        return CommunityMembership::firstOrCreate([
            'community_id' => $community->id,
            'user_id' => $user->id,
        ]);
    }

    public function leave(Community $community, User $user): void
    {
        CommunityMembership::where('community_id', $community->id)->where('user_id', $user->id)->delete();
    }

    public function isMember(Community $community, User $user): bool
    {
        return CommunityMembership::where('community_id', $community->id)->where('user_id', $user->id)->exists();
    }

    /**
     * @return array<int, string>
     */
    public function communityIdsFor(User $user): array
    {
        return CommunityMembership::where('user_id', $user->id)->pluck('community_id')->all();
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'communaute';
        $slug = $base;
        $suffix = 1;

        while (Community::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
