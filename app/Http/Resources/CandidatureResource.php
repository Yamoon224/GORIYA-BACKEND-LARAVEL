<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/candidatures/dto/candidature.vm.ts.
 */
#[OA\Schema(
    schema: 'Candidature',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'status', type: 'string', enum: ['EN_ATTENTE', 'EN_COURS', 'APPROUVEE', 'REJETEE']),
        new OA\Property(property: 'score', type: 'integer'),
        new OA\Property(property: 'appliedDate', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'user',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
            ]
        ),
        new OA\Property(
            property: 'jobOffer',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'title', type: 'string'),
            ]
        ),
        new OA\Property(property: 'candidatePhone', type: 'string', nullable: true),
        new OA\Property(property: 'candidateLocation', type: 'string', nullable: true),
        new OA\Property(property: 'coverLetter', type: 'string', nullable: true),
        new OA\Property(property: 'resume', ref: '#/components/schemas/UserResume', nullable: true),
        new OA\Property(
            property: 'answers',
            description: "Réponses aux questions de présélection de l'offre",
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'questionId', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'question', type: 'string'),
                new OA\Property(property: 'type', type: 'string'),
                new OA\Property(property: 'value', type: 'array', items: new OA\Items(type: 'string')),
            ], type: 'object')
        ),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class CandidatureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'candidateName' => $this->candidate_name,
            'candidateEmail' => $this->candidate_email,
            'status' => $this->status->value,
            'score' => $this->score,
            'appliedDate' => $this->applied_date,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'jobOffer' => $this->jobOffer ? [
                'id' => $this->jobOffer->id,
                'title' => $this->jobOffer->title,
            ] : null,
            'candidatePhone' => $this->candidate_phone,
            'candidateLocation' => $this->candidate_location,
            'coverLetter' => $this->cover_letter,
            'resume' => $this->resume ? (new UserResumeResource($this->resume))->resolve() : null,
            'answers' => $this->answers->map(fn ($answer) => [
                'id' => $answer->id,
                'questionId' => $answer->question_id,
                'question' => $answer->question_label,
                'type' => $answer->question_type->value,
                // Toujours une liste, même pour une réponse unique (cf. modèle).
                'value' => $answer->value ?? [],
            ])->values()->all(),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
