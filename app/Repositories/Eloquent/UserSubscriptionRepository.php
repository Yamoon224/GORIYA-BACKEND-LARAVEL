<?php

namespace App\Repositories\Eloquent;

use App\Enums\SubscriptionStatus;
use App\Models\UserSubscription;
use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserSubscriptionRepository extends BaseRepository implements UserSubscriptionRepositoryInterface
{
    protected function model(): string
    {
        return UserSubscription::class;
    }

    public function findActiveForUser(string $userId): ?UserSubscription
    {
        return UserSubscription::where('user_id', $userId)
            ->where('status', SubscriptionStatus::ACTIVE)
            ->notPastEndDate()
            ->with('plan')
            ->orderByDesc('created_at')
            ->first();
    }

    public function findActiveForUserAndPlan(?string $userId, ?string $planId): ?UserSubscription
    {
        return UserSubscription::where('user_id', $userId)
            ->where('plan_id', $planId)
            ->where('status', SubscriptionStatus::ACTIVE)
            ->notPastEndDate()
            ->with('plan')
            ->first();
    }

    public function expireOverdue(): int
    {
        return UserSubscription::where('status', SubscriptionStatus::ACTIVE)
            ->whereNotNull('end_date')
            ->where('end_date', '<', now())
            ->get()
            ->each(fn (UserSubscription $sub) => $sub->update(['status' => SubscriptionStatus::EXPIRED]))
            ->count();
    }

    public function cancelActiveForUser(string $userId): void
    {
        UserSubscription::where('user_id', $userId)
            ->where('status', SubscriptionStatus::ACTIVE)
            ->update(['status' => SubscriptionStatus::CANCELLED]);
    }

    public function findAllWithPlan(): Collection
    {
        return UserSubscription::with('plan')->get();
    }

    public function paginateAllWithPlanAndUser(int $page, int $limit): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        return UserSubscription::with(['plan', 'user'])
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }
}
