<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SubmitSurveyResponseRequest',
    required: ['answers'],
    properties: [
        new OA\Property(
            property: 'answers',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'questionId', type: 'string'),
                new OA\Property(property: 'value', description: 'entier (RATING) ou texte (TEXT)'),
            ])
        ),
    ]
)]
class SubmitSurveyResponseRequest extends FormRequest
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
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.questionId' => ['required', 'string'],
            'answers.*.value' => ['required'],
        ];
    }
}
