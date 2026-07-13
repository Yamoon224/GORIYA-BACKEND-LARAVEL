<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Pitch',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'jobOfferId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['EMPLOI', 'CONCOURS', 'APPEL_PROJET', 'STARTUP']),
        new OA\Property(property: 'format', type: 'string', enum: ['TEXT', 'VIDEO']),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'videoPath', type: 'string', nullable: true),
        new OA\Property(
            property: 'score',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'clarte', type: 'integer'),
                new OA\Property(property: 'impact', type: 'integer'),
                new OA\Property(property: 'persuasion', type: 'integer'),
                new OA\Property(property: 'feedback', type: 'string'),
            ]
        ),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'PROCESSING', 'READY', 'FAILED']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class PitchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jobOfferId' => $this->job_offer_id,
            'type' => $this->type,
            'format' => $this->format,
            'content' => $this->content,
            'videoPath' => $this->video_path,
            'score' => $this->score,
            'status' => $this->status,
            'createdAt' => $this->created_at,
        ];
    }
}
