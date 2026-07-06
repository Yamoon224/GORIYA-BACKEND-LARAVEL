<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/scoring/dto/scoring-result.vm.ts.
 */
#[OA\Schema(
    schema: 'ScoringResult',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'overallScore', type: 'number'),
        new OA\Property(property: 'criteria', type: 'object'),
        new OA\Property(property: 'analysisDate', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string', enum: ['COMPLETED', 'IN_PROGRESS']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class ScoringResultResource extends JsonResource
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
            'overallScore' => $this->overall_score,
            'criteria' => $this->criteria,
            'analysisDate' => $this->analysis_date,
            'status' => $this->status->value,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
