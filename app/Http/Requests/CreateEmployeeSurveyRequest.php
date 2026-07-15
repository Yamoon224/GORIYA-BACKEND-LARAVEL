<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateEmployeeSurveyRequest',
    required: ['title', 'questions'],
    properties: [
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
    ]
)]
class CreateEmployeeSurveyRequest extends FormRequest
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
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.id' => ['required', 'string'],
            'questions.*.question' => ['required', 'string'],
            'questions.*.type' => ['required', 'string', 'in:RATING,TEXT'],
        ];
    }
}
