<?php

namespace App\Http\Requests;

use App\Enums\CandidatureStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateCandidatureRequest',
    properties: [
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'status', type: 'string', enum: ['EN_ATTENTE', 'EN_COURS', 'APPROUVEE', 'REJETEE'], nullable: true),
        new OA\Property(property: 'score', type: 'number', nullable: true),
        new OA\Property(property: 'appliedDate', type: 'string', format: 'date'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'jobOfferId', type: 'string', format: 'uuid', nullable: true),
    ]
)]
class UpdateCandidatureRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(CandidatureStatus::class)],
            'score' => ['nullable', 'numeric'],
            'appliedDate' => ['sometimes', 'date'],
            'userId' => ['nullable', 'uuid'],
            'jobOfferId' => ['nullable', 'uuid'],
        ];
    }
}
