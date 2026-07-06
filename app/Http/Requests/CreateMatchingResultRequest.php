<?php

namespace App\Http\Requests;

use App\Enums\MatchingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateMatchingResultRequest',
    required: ['candidateName', 'candidateEmail', 'position', 'company', 'matchingScore', 'status', 'matchDate'],
    properties: [
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'company', type: 'string'),
        new OA\Property(property: 'matchingScore', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['NOUVEAU', 'EN_COURS', 'FINALISE']),
        new OA\Property(property: 'matchDate', type: 'string', format: 'date-time'),
    ]
)]
class CreateMatchingResultRequest extends FormRequest
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
            'company' => ['required', 'string'],
            'matchingScore' => ['required', 'numeric'],
            'status' => ['required', Rule::enum(MatchingStatus::class)],
            'matchDate' => ['required', 'date'],
        ];
    }
}
