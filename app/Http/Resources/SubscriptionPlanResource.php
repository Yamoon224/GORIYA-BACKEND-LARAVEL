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
        new OA\Property(
            property: 'featureLimits',
            type: 'object',
            nullable: true,
            description: "Tentatives autorisées par fonctionnalité limitée (clés cv_creation/document_generation/cv_analysis) ; une clé absente = fonctionnalité non incluse dans ce plan.",
            additionalProperties: new OA\AdditionalProperties(type: 'integer')
        ),
        new OA\Property(property: 'resetPrice', type: 'number', format: 'float', nullable: true, description: 'Montant XOF pour réinitialiser un compteur de tentatives à 0'),
        new OA\Property(
            property: 'includedFeatures',
            type: 'array',
            items: new OA\Items(type: 'string'),
            nullable: true,
            description: "Clés des fonctionnalités incluses sans quota numérique (ex. simulation_entretien, goriya_pitch, enquetes_internes, gestion_paie) — vérifiées côté backend par le middleware plan.feature."
        ),
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
            'featureLimits' => $this->feature_limits,
            'resetPrice' => $this->reset_price !== null ? (float) $this->reset_price : null,
            'includedFeatures' => $this->included_features,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
