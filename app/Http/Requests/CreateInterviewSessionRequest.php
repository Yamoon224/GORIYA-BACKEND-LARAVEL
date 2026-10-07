<?php

namespace App\Http\Requests;

use App\Enums\InterviewStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateInterviewSessionRequest',
    required: ['candidateName', 'candidateEmail', 'position', 'duration', 'status', 'startTime'],
    properties: [
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'duration', type: 'number'),
        new OA\Property(property: 'score', type: 'number', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'COMPLETED', 'SCHEDULED']),
        new OA\Property(property: 'startTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'feedback', type: 'string', nullable: true),
    ]
)]
class CreateInterviewSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * La page de simulation n'envoie que le poste visé. Le reste se déduit :
     * la session démarre maintenant, et elle appartient au compte connecté —
     * son nom et son email sont imposés, pour qu'un candidat ne puisse pas
     * ouvrir une session au nom d'un autre (c'est aussi cet email qui lui
     * donne ensuite accès à sa session). Un administrateur garde la main sur
     * tous les champs.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        $isAdmin = $user?->role === UserRole::ADMIN;

        $this->merge([
            'duration' => $this->input('duration', 0),
            'status' => $this->input('status', InterviewStatus::ACTIVE->value),
            'startTime' => $this->input('startTime', now()->toIso8601String()),
            'candidateName' => $isAdmin ? $this->input('candidateName', $user->name) : $user?->name,
            'candidateEmail' => $isAdmin ? $this->input('candidateEmail', $user->email) : $user?->email,
        ]);
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
            'duration' => ['required', 'numeric'],
            'score' => ['nullable', 'numeric'],
            'status' => ['required', Rule::enum(InterviewStatus::class)],
            'startTime' => ['required', 'date'],
            'feedback' => ['nullable', 'string'],
        ];
    }
}
