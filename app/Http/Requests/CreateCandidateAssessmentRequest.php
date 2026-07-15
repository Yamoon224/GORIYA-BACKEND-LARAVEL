<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateCandidateAssessmentRequest',
    properties: [
        new OA\Property(property: 'exchangeNotes', type: 'string', nullable: true, description: "Notes d'entretien/échange — utilisées pour l'analyse des soft skills"),
    ]
)]
class CreateCandidateAssessmentRequest extends FormRequest
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
            'exchangeNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
