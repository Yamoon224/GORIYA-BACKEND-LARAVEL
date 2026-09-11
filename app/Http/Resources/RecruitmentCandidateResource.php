<?php

namespace App\Http\Resources;

use App\Enums\RecruitmentInterviewStatus;
use App\Enums\RecruitmentStage;
use App\Models\Candidature;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentNote;
use App\Models\RecruitmentStageEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RecruitmentCandidate',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Identifiant de la candidature'),
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'jobOffer', type: 'object', nullable: true),
        new OA\Property(property: 'stage', type: 'string', enum: ['NEW', 'SCREENING', 'INTERVIEW', 'OFFER', 'HIRED', 'REJECTED']),
        new OA\Property(property: 'stageSince', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'nextInterview', type: 'object', nullable: true),
        new OA\Property(property: 'averageRating', type: 'number', nullable: true),
        new OA\Property(property: 'employeeId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'interviews', type: 'array', items: new OA\Items(ref: '#/components/schemas/RecruitmentInterview'), description: 'Détail uniquement'),
        new OA\Property(property: 'notes', type: 'array', items: new OA\Items(type: 'object'), description: 'Détail uniquement'),
        new OA\Property(property: 'history', type: 'array', items: new OA\Items(type: 'object'), description: 'Détail uniquement'),
    ]
)]
class RecruitmentCandidateResource extends JsonResource
{
    private bool $detail = false;

    /** Dossier complet : lettre, CV, réponses, entretiens, notes et historique. */
    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public static function note(RecruitmentNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'authorId' => $note->created_by,
            'authorName' => $note->author?->name,
            'createdAt' => $note->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Candidature $candidature */
        $candidature = $this->resource;
        // Coordonnées, compétences, CV et réponses : mêmes règles que la page Candidatures.
        $application = (new CandidatureResource($candidature))->resolve($request);
        $stage = $candidature->currentStage();
        $interviews = $candidature->relationLoaded('interviews') ? $candidature->interviews : collect();

        $scheduled = $interviews->filter(fn (RecruitmentInterview $i) => $i->status === RecruitmentInterviewStatus::SCHEDULED);
        $next = $scheduled->first(fn (RecruitmentInterview $i) => $i->scheduled_at->isFuture());
        $ratings = $interviews
            ->filter(fn (RecruitmentInterview $i) => $i->status === RecruitmentInterviewStatus::COMPLETED && $i->rating !== null)
            ->pluck('rating');

        $data = [
            'id' => $candidature->id,
            'userId' => $candidature->user_id,
            'candidateName' => $application['candidateName'],
            'candidateEmail' => $application['candidateEmail'],
            'candidateTitle' => $application['candidateTitle'],
            'candidateSkills' => $application['candidateSkills'],
            'candidatePhone' => $application['candidatePhone'],
            'candidateLocation' => $application['candidateLocation'],
            'jobOffer' => $candidature->jobOffer ? [
                'id' => $candidature->jobOffer->id,
                'title' => $candidature->jobOffer->title,
                'status' => $candidature->jobOffer->status?->value,
                'type' => $candidature->jobOffer->type?->value,
                'location' => $candidature->jobOffer->location,
            ] : null,
            'score' => $candidature->score,
            'appliedDate' => $candidature->applied_date?->toIso8601String(),
            'status' => $candidature->status?->value,
            'stage' => $stage->value,
            'stageSince' => $candidature->stageSince()?->toIso8601String(),
            'rejectionReason' => $stage === RecruitmentStage::REJECTED ? $candidature->rejection_reason : null,
            'hasResume' => ! empty($application['resume']['url'] ?? null),
            'assessmentScore' => $candidature->assessment?->overall_score,
            'interviewsCount' => $interviews->count(),
            'nextInterview' => $next ? [
                'id' => $next->id,
                'type' => $next->type?->value,
                'scheduledAt' => $next->scheduled_at->toIso8601String(),
            ] : null,
            // Entretiens dont la date est passée sans compte rendu.
            'feedbackDueCount' => $scheduled->filter(fn (RecruitmentInterview $i) => $i->scheduled_at->isPast())->count(),
            'averageRating' => $ratings->isEmpty() ? null : round((float) $ratings->avg(), 1),
            'notesCount' => (int) ($candidature->recruitment_notes_count ?? 0),
            'employeeId' => $candidature->employee?->id,
        ];

        if (! $this->detail) {
            return $data;
        }

        return $data + [
            'coverLetter' => $application['coverLetter'],
            'resume' => $application['resume'],
            'answers' => $application['answers'],
            'interviews' => RecruitmentInterviewResource::collection($interviews)->resolve($request),
            'notes' => $candidature->recruitmentNotes->map(fn (RecruitmentNote $note) => self::note($note))->values()->all(),
            'history' => $candidature->stageEvents->map(fn (RecruitmentStageEvent $event) => [
                'id' => $event->id,
                'fromStage' => $event->from_stage?->value,
                'toStage' => $event->to_stage?->value,
                'comment' => $event->comment,
                'authorName' => $event->creator?->name,
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
