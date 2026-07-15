<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EmployeeSurvey',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(
            property: 'questions',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'string'),
                new OA\Property(property: 'question', type: 'string'),
                new OA\Property(property: 'type', type: 'string', enum: ['RATING', 'TEXT']),
            ])
        ),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'ACTIVE', 'CLOSED']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class EmployeeSurveyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'questions' => $this->questions,
            'status' => $this->status,
            'createdAt' => $this->created_at,
        ];
    }
}
