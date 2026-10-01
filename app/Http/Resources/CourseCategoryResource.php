<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CourseCategory',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'icon', type: 'string', nullable: true),
        new OA\Property(property: 'coverUrl', type: 'string', nullable: true),
        new OA\Property(property: 'sortOrder', type: 'integer'),
        new OA\Property(property: 'isActive', type: 'boolean'),
        new OA\Property(property: 'coursesCount', type: 'integer', nullable: true),
    ]
)]
class CourseCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'coverPath' => $this->cover_path,
            'coverUrl' => MediaUrl::resolve($this->cover_path),
            'sortOrder' => $this->sort_order,
            'isActive' => $this->is_active,
            'coursesCount' => $this->courses_count ?? null,
        ];
    }
}
