<?php

namespace App\Http\Requests;

use App\Enums\ScoringStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateScoringResultRequest',
    properties: [
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'overallScore', type: 'number'),
        new OA\Property(property: 'criteria', type: 'object'),
        new OA\Property(property: 'analysisDate', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string', enum: ['COMPLETED', 'IN_PROGRESS'], nullable: true),
    ]
)]
class UpdateScoringResultRequest extends FormRequest
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
            'candidateName' => ['sometimes', 'string'],
            'candidateEmail' => ['sometimes', 'email'],
            'position' => ['sometimes', 'string'],
            'overallScore' => ['sometimes', 'numeric'],
            'criteria' => ['sometimes', 'array'],
            'analysisDate' => ['sometimes', 'date'],
            'status' => ['nullable', Rule::enum(ScoringStatus::class)],
        ];
    }
}
