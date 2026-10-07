<?php

namespace App\Http\Requests;

use App\Enums\InterviewStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateInterviewSessionRequest',
    properties: [
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'duration', type: 'number'),
        new OA\Property(property: 'score', type: 'number', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'COMPLETED', 'SCHEDULED'], nullable: true),
        new OA\Property(property: 'startTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'feedback', type: 'string', nullable: true),
    ]
)]
class UpdateInterviewSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * L'email du candidat désigne le propriétaire de la session : seul un
     * administrateur peut le changer, comme le nom qui l'accompagne.
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        // Filtré sur les données validées, et non sur l'entrée : un champ
        // glissé dans l'URL plutôt que dans le corps passerait sinon.
        if ($this->user()?->role !== UserRole::ADMIN) {
            unset($data['candidateName'], $data['candidateEmail']);
        }

        return data_get($data, $key, $default);
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
            'duration' => ['sometimes', 'numeric'],
            'score' => ['nullable', 'numeric'],
            'status' => ['nullable', Rule::enum(InterviewStatus::class)],
            'startTime' => ['sometimes', 'date'],
            'feedback' => ['nullable', 'string'],
        ];
    }
}
