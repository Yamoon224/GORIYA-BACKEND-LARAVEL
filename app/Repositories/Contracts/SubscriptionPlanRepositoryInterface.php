<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface SubscriptionPlanRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, \App\Models\SubscriptionPlan>
     */
    public function findActive(?string $userType): Collection;
}
