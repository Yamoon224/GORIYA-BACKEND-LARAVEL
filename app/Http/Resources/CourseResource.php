<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Course',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'slug', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'subtitle', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'category', type: 'string', nullable: true),
        new OA\Property(property: 'categoryRef', ref: '#/components/schemas/CourseCategory', nullable: true),
        new OA\Property(property: 'provider', type: 'string', nullable: true),
        new OA\Property(property: 'instructor', ref: '#/components/schemas/Instructor', nullable: true),
        new OA\Property(property: 'level', type: 'string', enum: ['BEGINNER', 'INTERMEDIATE', 'ADVANCED']),
        new OA\Property(property: 'language', type: 'string', example: 'fr'),
        new OA\Property(property: 'subtitleLanguages', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'learningOutcomes', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'durationHours', type: 'integer', nullable: true),
        new OA\Property(property: 'thumbnailPath', type: 'string', nullable: true),
        new OA\Property(property: 'thumbnailUrl', type: 'string', nullable: true),
        new OA\Property(property: 'trailerUrl', type: 'string', nullable: true),
        new OA\Property(property: 'isFree', type: 'boolean'),
        new OA\Property(property: 'isPublished', type: 'boolean'),
        new OA\Property(property: 'isActive', type: 'boolean'),
        new OA\Property(property: 'lessonsCount', type: 'integer'),
        new OA\Property(property: 'totalMinutes', type: 'integer'),
        new OA\Property(property: 'publishedAt', type: 'string', format: 'date-time', nullable: true),
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
            'slug' => $this->slug,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'category' => $this->category,
            'categoryId' => $this->category_id,
            'categoryRef' => $this->whenLoaded('categoryRef', fn () => $this->categoryRef ? (new CourseCategoryResource($this->categoryRef))->resolve() : null),
            'provider' => $this->provider,
            'instructorId' => $this->instructor_id,
            'instructor' => $this->whenLoaded('instructor', fn () => $this->instructor ? (new InstructorResource($this->instructor))->resolve() : null),
            'level' => $this->level,
            'language' => $this->language,
            'subtitleLanguages' => $this->subtitle_languages ?? [],
            'learningOutcomes' => $this->learning_outcomes ?? [],
            'durationHours' => $this->duration_hours,
            'thumbnailPath' => $this->thumbnail_path,
            'thumbnailUrl' => MediaUrl::resolve($this->thumbnail_path),
            'trailerUrl' => $this->trailer_url,
            'isFree' => (bool) $this->is_free,
            'isPublished' => (bool) $this->is_published,
            'isActive' => (bool) $this->is_active,
            'lessonsCount' => (int) $this->lessons_count,
            'totalMinutes' => (int) $this->total_minutes,
            'publishedAt' => $this->published_at,
        ];
    }
}
