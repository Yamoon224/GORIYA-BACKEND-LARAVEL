<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MyInterview',
    description: "Entretien du candidat authentifié, vu depuis son propre espace (aucune donnée RH interne)",
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['PHONE', 'VIDEO', 'ONSITE']),
        new OA\Property(property: 'typeLabel', type: 'string'),
        new OA\Property(property: 'scheduledAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'endsAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'durationMinutes', type: 'integer'),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['SCHEDULED', 'COMPLETED', 'CANCELLED', 'NO_SHOW']),
        new OA\Property(property: 'companyName', type: 'string', nullable: true),
        new OA\Property(property: 'jobOfferTitle', type: 'string', nullable: true),
        new OA\Property(property: 'callSession', type: 'object', nullable: true, description: 'Salle GORIYA Meet (entretien VIDEO)', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'status', type: 'string', enum: ['SCHEDULED', 'ACTIVE', 'ENDED']),
        ]),
    ]
)]
class MyInterviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $offer = $this->candidature?->jobOffer;

        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'typeLabel' => $this->type?->label(),
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'endsAt' => $this->scheduled_at?->addMinutes($this->duration_minutes ?? 45)->toIso8601String(),
            'durationMinutes' => $this->duration_minutes,
            'location' => $this->location,
            'status' => $this->status?->value,
            'companyName' => $offer?->company?->name,
            'jobOfferTitle' => $offer?->title,
            'callSession' => $this->callSession ? [
                'id' => $this->callSession->id,
                'status' => $this->callSession->status?->value,
            ] : null,
        ];
    }
}
