<?php

namespace App\Http\Requests;

use App\Enums\ScoringStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateScoringResultRequest',
    required: ['candidateName', 'candidateEmail', 'position', 'overallScore', 'criteria', 'analysisDate', 'status'],
    properties: [
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'overallScore', type: 'number'),
        new OA\Property(property: 'criteria', type: 'object'),
        new OA\Property(property: 'analysisDate', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string', enum: ['COMPLETED', 'IN_PROGRESS']),
    ]
)]
class CreateScoringResultRequest extends FormRequest
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
            'candidateName' => ['required', 'string'],
            'candidateEmail' => ['required', 'email'],
            'position' => ['required', 'string'],
            'overallScore' => ['required', 'numeric'],
            // @IsObject() côté Nest : un objet JSON arrive côté PHP comme un
            // tableau associatif, d'où la règle 'array'.
            'criteria' => ['required', 'array'],
            'analysisDate' => ['required', 'date'],
            'status' => ['required', Rule::enum(ScoringStatus::class)],
        ];
    }
}
