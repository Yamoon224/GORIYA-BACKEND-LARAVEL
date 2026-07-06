<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UserSubscription',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'planId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'plan', ref: '#/components/schemas/SubscriptionPlan', nullable: true),
        new OA\Property(
            property: 'user',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
            ]
        ),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'EXPIRED', 'CANCELLED']),
        new OA\Property(property: 'startDate', type: 'string', format: 'date-time'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date-time'),
        new OA\Property(property: 'autoRenew', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class UserSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'planId' => $this->plan_id,
            'plan' => $this->relationLoaded('plan') && $this->plan
                ? new SubscriptionPlanResource($this->plan)
                : null,
            'user' => $this->relationLoaded('user') && $this->user
                ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email]
                : null,
            'status' => $this->status->value,
            'startDate' => $this->start_date,
            'endDate' => $this->end_date,
            'autoRenew' => $this->auto_renew,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
