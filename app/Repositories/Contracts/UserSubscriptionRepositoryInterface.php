<?php

namespace App\Repositories\Contracts;

use App\Models\UserSubscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UserSubscriptionRepositoryInterface extends RepositoryInterface
{
    public function findActiveForUser(string $userId): ?UserSubscription;

    /**
     * $userId/$planId proviennent de query params non validés côté source
     * (verifyCheckout) — acceptent explicitement null plutôt que de
     * planter, comme le WHERE Eloquent le ferait de toute façon.
     */
    public function findActiveForUserAndPlan(?string $userId, ?string $planId): ?UserSubscription;

    public function cancelActiveForUser(string $userId): void;

    /**
     * @return Collection<int, UserSubscription>
     */
    public function findAllWithPlan(): Collection;

    public function paginateAllWithPlanAndUser(int $page, int $limit): LengthAwarePaginator;
}
