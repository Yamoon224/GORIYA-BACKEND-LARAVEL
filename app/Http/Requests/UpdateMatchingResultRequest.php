<?php

namespace App\Http\Requests;

use App\Enums\MatchingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateMatchingResultRequest',
    properties: [
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'company', type: 'string'),
        new OA\Property(property: 'matchingScore', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['NOUVEAU', 'EN_COURS', 'FINALISE'], nullable: true),
        new OA\Property(property: 'matchDate', type: 'string', format: 'date-time'),
    ]
)]
class UpdateMatchingResultRequest extends FormRequest
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
            'company' => ['sometimes', 'string'],
            'matchingScore' => ['sometimes', 'numeric'],
            'status' => ['nullable', Rule::enum(MatchingStatus::class)],
            'matchDate' => ['sometimes', 'date'],
        ];
    }
}
