<?php

namespace App\Http\Requests;

use App\Enums\CourseLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Création (POST, titre obligatoire) et modification partielle (PATCH) d'un
 * cours côté admin.
 */
#[OA\Schema(
    schema: 'SaveCourseRequest',
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'subtitle', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'categoryId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'instructorId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'level', type: 'string', enum: ['BEGINNER', 'INTERMEDIATE', 'ADVANCED']),
        new OA\Property(property: 'language', type: 'string', example: 'fr'),
        new OA\Property(property: 'subtitleLanguages', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'learningOutcomes', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'thumbnailPath', type: 'string', nullable: true),
        new OA\Property(property: 'trailerUrl', type: 'string', nullable: true),
        new OA\Property(property: 'isFree', type: 'boolean'),
        new OA\Property(property: 'isPublished', type: 'boolean'),
        new OA\Property(property: 'isActive', type: 'boolean'),
    ]
)]
class SaveCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'categoryId' => ['sometimes', 'nullable', 'uuid', 'exists:course_categories,id'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:150'],
            'instructorId' => ['sometimes', 'nullable', 'uuid', 'exists:instructors,id'],
            'level' => ['sometimes', Rule::enum(CourseLevel::class)],
            'language' => ['sometimes', 'string', 'max:5'],
            'subtitleLanguages' => ['sometimes', 'nullable', 'array', 'max:10'],
            'subtitleLanguages.*' => ['string', 'max:5'],
            'learningOutcomes' => ['sometimes', 'nullable', 'array', 'max:20'],
            'learningOutcomes.*' => ['string', 'max:255'],
            'durationHours' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'thumbnailPath' => ['sometimes', 'nullable', 'string', 'max:500'],
            'trailerUrl' => ['sometimes', 'nullable', 'url', 'max:500'],
            'isFree' => ['sometimes', 'boolean'],
            'isPublished' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
