<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RecruitmentInterview',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'candidatureId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'candidate', type: 'object', nullable: true, description: "Liste de l'entreprise uniquement"),
        new OA\Property(property: 'type', type: 'string', enum: ['PHONE', 'VIDEO', 'ONSITE']),
        new OA\Property(property: 'scheduledAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'endsAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'durationMinutes', type: 'integer'),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'meetingUrl', type: 'string', nullable: true),
        new OA\Property(property: 'interviewers', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['SCHEDULED', 'COMPLETED', 'CANCELLED', 'NO_SHOW']),
        new OA\Property(property: 'rating', type: 'integer', nullable: true),
        new OA\Property(property: 'recommendation', type: 'string', enum: ['HIRE', 'MAYBE', 'NO_HIRE'], nullable: true),
        new OA\Property(property: 'feedback', type: 'string', nullable: true),
        new OA\Property(property: 'candidateNotifiedAt', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class RecruitmentInterviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'candidatureId' => $this->candidature_id,
            'candidate' => $this->whenLoaded('candidature', fn () => $this->candidature ? [
                'id' => $this->candidature->id,
                'name' => $this->candidature->candidate_name,
                'email' => $this->candidature->candidate_email,
                'jobOfferId' => $this->candidature->job_offer_id,
                'jobOfferTitle' => $this->candidature->jobOffer?->title,
                'stage' => $this->candidature->currentStage()->value,
            ] : null),
            'type' => $this->type?->value,
            // Avec décalage horaire : le navigateur affiche l'heure locale du recruteur.
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'endsAt' => $this->scheduled_at?->addMinutes($this->duration_minutes ?? 45)->toIso8601String(),
            'durationMinutes' => $this->duration_minutes,
            'location' => $this->location,
            'meetingUrl' => $this->meeting_url,
            'interviewers' => $this->interviewers,
            'description' => $this->description,
            'status' => $this->status?->value,
            'rating' => $this->rating,
            'recommendation' => $this->recommendation?->value,
            'feedback' => $this->feedback,
            'candidateNotifiedAt' => $this->candidate_notified_at?->toIso8601String(),
            'createdByName' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'outcomeByName' => $this->whenLoaded('outcomeAuthor', fn () => $this->outcomeAuthor?->name),
            'outcomeAt' => $this->outcome_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
