<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Transaction',
    description: "Tentative de paiement (succès ou non) — sert d'historique de facturation.",
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'planId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'planName', type: 'string', nullable: true),
        new OA\Property(property: 'gateway', type: 'string', enum: ['kkiapay', 'wave', 'stripe', 'paiementpro']),
        new OA\Property(property: 'amount', type: 'number', format: 'float'),
        new OA\Property(property: 'currency', type: 'string', example: 'XOF'),
        new OA\Property(property: 'status', type: 'string', enum: ['PENDING', 'SUCCESS', 'FAILED', 'REFUNDED']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'planId' => $this->plan_id,
            'planName' => $this->relationLoaded('plan') ? $this->plan?->name : null,
            'gateway' => $this->gateway->value,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'createdAt' => $this->created_at,
        ];
    }
}
