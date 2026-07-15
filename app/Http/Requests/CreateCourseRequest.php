<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateCourseRequest',
    required: ['title'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'category', type: 'string', nullable: true),
        new OA\Property(property: 'provider', type: 'string', nullable: true),
        new OA\Property(property: 'durationHours', type: 'integer', nullable: true),
    ]
)]
class CreateCourseRequest extends FormRequest
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
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:100'],
            'provider' => ['nullable', 'string', 'max:150'],
            'durationHours' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
