<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateEmployeeSurveyRequest',
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
            ]),
            description: 'Refusé (400) si des réponses ont déjà été reçues — voir EmployeeSurveyService::update().'
        ),
        new OA\Property(property: 'dueDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'department', type: 'string', nullable: true),
    ]
)]
class UpdateEmployeeSurveyRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'questions' => ['sometimes', 'array', 'min:1'],
            'questions.*.id' => ['required_with:questions', 'string'],
            'questions.*.question' => ['required_with:questions', 'string'],
            'questions.*.type' => ['required_with:questions', 'string', 'in:RATING,TEXT'],
            'dueDate' => ['sometimes', 'nullable', 'date'],
            'department' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
