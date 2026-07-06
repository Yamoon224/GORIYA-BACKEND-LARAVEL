<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SubscriptionPlan',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'price', type: 'number', format: 'float'),
        new OA\Property(property: 'billingPeriod', type: 'string', enum: ['MONTHLY', 'ANNUAL']),
        new OA\Property(property: 'userType', type: 'string', enum: ['USER', 'ENTREPRISE']),
        new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'isActive', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class SubscriptionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'billingPeriod' => $this->billing_period->value,
            'userType' => $this->user_type->value,
            'features' => $this->features,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
