<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Course',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'category', type: 'string', nullable: true),
        new OA\Property(property: 'provider', type: 'string', nullable: true),
        new OA\Property(property: 'durationHours', type: 'integer', nullable: true),
        new OA\Property(property: 'thumbnailPath', type: 'string', nullable: true),
    ]
)]
class CourseResource extends JsonResource
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
            'category' => $this->category,
            'provider' => $this->provider,
            'durationHours' => $this->duration_hours,
            'thumbnailPath' => $this->thumbnail_path,
        ];
    }
}
