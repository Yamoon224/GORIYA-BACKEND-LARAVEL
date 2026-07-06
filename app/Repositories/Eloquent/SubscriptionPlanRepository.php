<?php

namespace App\Repositories\Eloquent;

use App\Models\SubscriptionPlan;
use App\Repositories\Contracts\SubscriptionPlanRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionPlanRepository extends BaseRepository implements SubscriptionPlanRepositoryInterface
{
    protected function model(): string
    {
        return SubscriptionPlan::class;
    }

    public function findActive(?string $userType): Collection
    {
        $query = SubscriptionPlan::where('is_active', true);

        if ($userType) {
            $query->where('user_type', $userType);
        }

        return $query->orderBy('price')->get();
    }
}
