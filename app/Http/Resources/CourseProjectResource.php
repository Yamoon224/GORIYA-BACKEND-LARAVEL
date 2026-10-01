<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CourseProject',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'linkUrl', type: 'string', nullable: true),
        new OA\Property(property: 'fileName', type: 'string', nullable: true),
        new OA\Property(property: 'hasFile', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['SUBMITTED', 'REVIEWED']),
        new OA\Property(property: 'feedback', type: 'string', nullable: true),
        new OA\Property(property: 'course', type: 'object', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class CourseProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courseId' => $this->course_id,
            'course' => $this->whenLoaded('course', fn () => $this->course ? [
                'id' => $this->course->id,
                'slug' => $this->course->slug,
                'title' => $this->course->title,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),
            'title' => $this->title,
            'description' => $this->description,
            'linkUrl' => $this->link_url,
            'fileName' => $this->file_name,
            'hasFile' => (bool) $this->file_path,
            'status' => $this->status,
            'feedback' => $this->feedback,
            'reviewedAt' => $this->reviewed_at,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
