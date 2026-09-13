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
        new OA\Property(property: 'availablePeriods', type: 'array', items: new OA\Items(type: 'integer'), nullable: true, description: 'Durées en mois choisissables au checkout (ex. [1,3,6,12]) ; absent/null = fixe à 1 mois'),
        new OA\Property(property: 'userType', type: 'string', enum: ['USER', 'ENTREPRISE']),
        new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'notificationLevel', type: 'string', enum: ['FAIBLE', 'ELEVE'], nullable: true),
        new OA\Property(property: 'attemptLimit', type: 'integer', nullable: true, description: 'Nombre de tentatives (création CV / génération documents / analyse CV) avant réinitialisation payante'),
        new OA\Property(property: 'resetPrice', type: 'number', format: 'float', nullable: true, description: 'Montant XOF pour réinitialiser les tentatives à 0'),
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
            'availablePeriods' => $this->available_periods,
            'userType' => $this->user_type->value,
            'features' => $this->features,
            'notificationLevel' => $this->notification_level,
            'attemptLimit' => $this->attempt_limit,
            'resetPrice' => $this->reset_price !== null ? (float) $this->reset_price : null,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
