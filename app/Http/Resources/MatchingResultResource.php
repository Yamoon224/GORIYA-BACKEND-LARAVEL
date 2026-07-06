<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/matching-results/dto/matching-result.vm.ts.
 */
#[OA\Schema(
    schema: 'MatchingResult',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'company', type: 'string'),
        new OA\Property(property: 'matchingScore', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['NOUVEAU', 'EN_COURS', 'FINALISE']),
        new OA\Property(property: 'matchDate', type: 'string', format: 'date-time'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class MatchingResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'candidateName' => $this->candidate_name,
            'candidateEmail' => $this->candidate_email,
            'position' => $this->position,
            'company' => $this->company,
            'matchingScore' => $this->matching_score,
            'status' => $this->status->value,
            'matchDate' => $this->match_date,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
