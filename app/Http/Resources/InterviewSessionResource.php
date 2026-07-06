<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/interview-sessions/dto/interview-session.vm.ts.
 */
#[OA\Schema(
    schema: 'InterviewSession',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'duration', type: 'number'),
        new OA\Property(property: 'score', type: 'number', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'COMPLETED', 'SCHEDULED']),
        new OA\Property(property: 'startTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'feedback', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class InterviewSessionResource extends JsonResource
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
            'duration' => $this->duration,
            'score' => $this->score,
            'status' => $this->status->value,
            'startTime' => $this->start_time,
            'feedback' => $this->feedback,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
