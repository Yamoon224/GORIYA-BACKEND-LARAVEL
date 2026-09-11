<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\RecruitmentInterviewStatus;
use App\Enums\RecruitmentStage;
use App\Models\Candidature;
use App\Models\JobOffer;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentNote;
use App\Models\RecruitmentStageEvent;
use App\Models\User;
use App\Services\Concerns\MapsFieldsToColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline de recrutement des Services RH, au-dessus des candidatures reçues
 * sur les offres de l'entreprise.
 *
 * Invariants :
 *  - l'étape et le statut public avancent ensemble : le candidat est notifié
 *    exactement comme depuis la page Candidatures ;
 *  - « Embauché » ne s'atteint qu'en créant la fiche employé, et n'en sort plus ;
 *  - chaque changement d'étape laisse une trace datée et signée.
 */
class RecruitmentService
{
    use MapsFieldsToColumns;

    /** Cartes du pipeline : compétences, CV, note IA et entretiens sans requête par carte. */
    private const LIST_RELATIONS = ['user.portfolios', 'user.cv', 'jobOffer', 'resume', 'answers', 'assessment', 'employee', 'interviews'];

    private const DETAIL_RELATIONS = ['stageEvents.creator', 'recruitmentNotes.author', 'interviews.creator', 'interviews.outcomeAuthor'];

    private const INTERVIEW_RELATIONS = ['candidature.jobOffer', 'candidature.employee', 'creator', 'outcomeAuthor'];

    private const INTERVIEW_FIELDS = [
        'type' => 'type',
        'scheduledAt' => 'scheduled_at',
        'durationMinutes' => 'duration_minutes',
        'location' => 'location',
        'meetingUrl' => 'meeting_url',
        'interviewers' => 'interviewers',
        'description' => 'description',
    ];

    /** Issues possibles d'un entretien. Un compte rendu se complète après coup. */
    private const OUTCOME_TRANSITIONS = [
        'SCHEDULED' => ['COMPLETED', 'NO_SHOW', 'CANCELLED'],
        'COMPLETED' => ['COMPLETED'],
    ];

    public function __construct(
        private readonly CandidatureService $candidatures,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{jobOfferId?: ?string, stage?: ?list<string>, search?: ?string}  $filters
     */
    public function listCandidates(string $companyId, array $filters = []): Collection
    {
        $query = $this->companyCandidatures($companyId)
            ->with(self::LIST_RELATIONS)
            ->withCount('recruitmentNotes');

        if ($jobOfferId = $filters['jobOfferId'] ?? null) {
            $query->where('job_offer_id', $jobOfferId);
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('candidate_name', 'like', "%{$search}%")
                    ->orWhere('candidate_email', 'like', "%{$search}%")
                    ->orWhereHas('jobOffer', fn (Builder $offer) => $offer->where('title', 'like', "%{$search}%"));
            });
        }

        $candidates = $query->orderByDesc('applied_date')->get();

        // L'étape se déduit en partie du statut et de la fiche employé : on
        // filtre ici plutôt que de dupliquer cette règle en SQL.
        if (! empty($filters['stage'])) {
            $candidates = $candidates
                ->filter(fn (Candidature $candidature) => in_array($candidature->currentStage()->value, $filters['stage'], true))
                ->values();
        }

        return $candidates;
    }

    /** Candidat avec son dossier complet : entretiens, notes et historique. */
    public function findCandidate(string $id, string $companyId): ?Candidature
    {
        return $this->companyCandidatures($companyId)
            ->with(array_merge(self::LIST_RELATIONS, self::DETAIL_RELATIONS))
            ->withCount('recruitmentNotes')
            ->find($id);
    }

    public function findCandidature(string $id, string $companyId): ?Candidature
    {
        return $this->companyCandidatures($companyId)->with(['jobOffer', 'employee'])->find($id);
    }

    /**
     * Offres de l'entreprise (brouillons exclus : ils ne reçoivent pas de
     * candidature) et répartition de leurs candidats par étape.
     *
     * @return list<array<string, mixed>>
     */
    public function jobOffers(string $companyId): array
    {
        $empty = array_fill_keys(array_map(fn (RecruitmentStage $stage) => $stage->value, RecruitmentStage::cases()), 0);

        return JobOffer::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', JobStatus::DRAFT->value)
            ->with('candidatures.employee')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (JobOffer $offer) use ($empty) {
                $stages = $empty;
                foreach ($offer->candidatures as $candidature) {
                    $stages[$candidature->currentStage()->value]++;
                }

                return [
                    'id' => $offer->id,
                    'title' => $offer->title,
                    'status' => $offer->status?->value,
                    'type' => $offer->type?->value,
                    'location' => $offer->location,
                    'publishDate' => $offer->publish_date?->toDateString(),
                    'endDate' => $offer->end_date?->toDateString(),
                    'candidatesCount' => $offer->candidatures->count(),
                    'stages' => $stages,
                ];
            })
            ->values()
            ->all();
    }

    public function moveStage(Candidature $candidature, RecruitmentStage $to, User $author, ?string $comment = null): Candidature
    {
        $from = $candidature->currentStage();

        if ($from === RecruitmentStage::HIRED) {
            abort(400, 'Ce candidat a été embauché : sa fiche employé fait désormais foi.');
        }
        if ($to === RecruitmentStage::HIRED) {
            abort(400, "L'embauche se fait en créant la fiche employé : utilisez « Embaucher ».");
        }
        if ($from === $to) {
            return $candidature;
        }

        DB::transaction(function () use ($candidature, $from, $to, $author, $comment) {
            if ($to === RecruitmentStage::REJECTED) {
                // Un candidat écarté n'a plus d'entretien à passer.
                $candidature->interviews()
                    ->where('status', RecruitmentInterviewStatus::SCHEDULED->value)
                    ->update([
                        'status' => RecruitmentInterviewStatus::CANCELLED->value,
                        'outcome_by' => $author->id,
                        'outcome_at' => now(),
                    ]);
            }

            $this->record($candidature, $from, $to, $author, $comment);
        });

        // Statut public en dernier, hors transaction : sa mise à jour notifie le
        // candidat et déclenche les webhooks de l'entreprise.
        if ($candidature->status !== $to->candidatureStatus()) {
            $this->candidatures->update($candidature, ['status' => $to->candidatureStatus()->value]);
        }

        return $candidature->refresh();
    }

    /** Création d'une fiche employé depuis la candidature (EmployeeService) : fin du parcours. */
    public function markHired(Candidature $candidature, ?User $author): void
    {
        $this->record($candidature, $candidature->recordedStage(), RecruitmentStage::HIRED, $author, null);
    }

    public function addNote(Candidature $candidature, User $author, string $body): RecruitmentNote
    {
        return RecruitmentNote::create([
            'company_id' => $candidature->jobOffer->company_id,
            'candidature_id' => $candidature->id,
            'body' => $body,
            'created_by' => $author->id,
        ])->load('author');
    }

    public function findNote(string $id, string $companyId): ?RecruitmentNote
    {
        return RecruitmentNote::where('company_id', $companyId)->find($id);
    }

    /**
     * @param  array{from?: ?string, to?: ?string, status?: ?list<string>}  $filters
     */
    public function listInterviews(string $companyId, array $filters = []): Collection
    {
        $query = RecruitmentInterview::query()
            ->where('company_id', $companyId)
            ->with(self::INTERVIEW_RELATIONS);

        if ($from = $filters['from'] ?? null) {
            $query->where('scheduled_at', '>=', CarbonImmutable::parse($from)->startOfDay());
        }
        if ($to = $filters['to'] ?? null) {
            $query->where('scheduled_at', '<=', CarbonImmutable::parse($to)->endOfDay());
        }
        if (! empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }

        return $query->orderBy('scheduled_at')->get();
    }

    public function findInterview(string $id, string $companyId): ?RecruitmentInterview
    {
        return RecruitmentInterview::where('company_id', $companyId)->with(self::INTERVIEW_RELATIONS)->find($id);
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (camelCase)
     */
    public function scheduleInterview(Candidature $candidature, User $author, array $data): RecruitmentInterview
    {
        $stage = $candidature->currentStage();
        if ($stage === RecruitmentStage::HIRED) {
            abort(400, 'Ce candidat a déjà été embauché.');
        }
        if ($stage === RecruitmentStage::REJECTED) {
            abort(400, 'Ce candidat a été écarté : replacez-le dans le pipeline avant de planifier un entretien.');
        }

        $interview = RecruitmentInterview::create($this->interviewAttributes($data) + [
            'company_id' => $candidature->jobOffer->company_id,
            'candidature_id' => $candidature->id,
            'status' => RecruitmentInterviewStatus::SCHEDULED,
            'created_by' => $author->id,
        ]);

        // Planifier un entretien fait avancer un candidat encore au tri.
        if (in_array($stage, [RecruitmentStage::NEW, RecruitmentStage::SCREENING], true)) {
            $this->moveStage($candidature, RecruitmentStage::INTERVIEW, $author);
        }

        $this->notifyIfRequested($interview, $data, false);

        return $this->reloadInterview($interview);
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (camelCase)
     */
    public function updateInterview(RecruitmentInterview $interview, array $data): RecruitmentInterview
    {
        if ($interview->status !== RecruitmentInterviewStatus::SCHEDULED) {
            abort(400, 'Seul un entretien encore planifié se modifie : complétez plutôt son compte rendu.');
        }

        $previous = $interview->scheduled_at->getTimestamp();
        $interview->update($this->interviewAttributes($data));

        if ($interview->scheduled_at->getTimestamp() !== $previous) {
            $this->notifyIfRequested($interview, $data, true);
        }

        return $this->reloadInterview($interview);
    }

    /**
     * @param  array{status: string, rating?: ?int, recommendation?: ?string, feedback?: ?string, notifyCandidate?: ?bool}  $data
     */
    public function recordOutcome(RecruitmentInterview $interview, User $author, array $data): RecruitmentInterview
    {
        $status = RecruitmentInterviewStatus::from($data['status']);
        if (! in_array($status->value, self::OUTCOME_TRANSITIONS[$interview->status->value] ?? [], true)) {
            abort(400, 'Cet entretien ne peut plus passer à ce statut.');
        }

        $completed = $status === RecruitmentInterviewStatus::COMPLETED;
        $interview->update([
            'status' => $status,
            'rating' => $completed ? ($data['rating'] ?? null) : null,
            'recommendation' => $completed ? ($data['recommendation'] ?? null) : null,
            // Compte rendu, ou motif d'une annulation / d'une absence.
            'feedback' => $data['feedback'] ?? null,
            'outcome_by' => $author->id,
            'outcome_at' => now(),
        ]);

        // On ne prévient d'une annulation que le candidat qui avait été convoqué.
        if ($status === RecruitmentInterviewStatus::CANCELLED
            && ($data['notifyCandidate'] ?? true)
            && $interview->candidate_notified_at
            && $interview->scheduled_at->isFuture()) {
            $this->notifications->notifyInterviewCancelled($interview);
        }

        return $this->reloadInterview($interview);
    }

    public function deleteInterview(RecruitmentInterview $interview): void
    {
        if ($interview->status === RecruitmentInterviewStatus::COMPLETED) {
            abort(400, 'Un entretien passé et son compte rendu font partie du dossier du candidat : il ne se supprime pas.');
        }

        $interview->delete();
    }

    private function record(Candidature $candidature, ?RecruitmentStage $from, RecruitmentStage $to, ?User $author, ?string $comment): void
    {
        $candidature->update([
            'pipeline_stage' => $to,
            'stage_changed_at' => now(),
            'rejection_reason' => $to === RecruitmentStage::REJECTED ? $comment : null,
        ]);

        RecruitmentStageEvent::create([
            'company_id' => $candidature->jobOffer->company_id,
            'candidature_id' => $candidature->id,
            'from_stage' => $from,
            'to_stage' => $to,
            'comment' => $comment,
            'created_by' => $author?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function interviewAttributes(array $data): array
    {
        $attributes = $this->mapFields($data, self::INTERVIEW_FIELDS);

        if (isset($attributes['scheduled_at'])) {
            // Ramené au fuseau de l'application, quel que soit le décalage envoyé par le navigateur.
            $attributes['scheduled_at'] = CarbonImmutable::parse($attributes['scheduled_at'])->setTimezone(config('app.timezone'));
        }
        if (array_key_exists('duration_minutes', $attributes) && $attributes['duration_minutes'] === null) {
            unset($attributes['duration_minutes']);
        }

        return $attributes;
    }

    /**
     * Convocation du candidat : par défaut, et seulement pour un entretien à
     * venir (un entretien saisi après coup ne prévient personne).
     *
     * @param  array<string, mixed>  $data
     */
    private function notifyIfRequested(RecruitmentInterview $interview, array $data, bool $rescheduled): void
    {
        if (! ($data['notifyCandidate'] ?? true) || ! $interview->scheduled_at->isFuture()) {
            return;
        }

        $this->notifications->notifyInterviewScheduled($interview, $rescheduled);
        $interview->update(['candidate_notified_at' => now()]);
    }

    private function companyCandidatures(string $companyId): Builder
    {
        return Candidature::query()->whereHas('jobOffer', fn (Builder $q) => $q->where('company_id', $companyId));
    }

    private function reloadInterview(RecruitmentInterview $interview): RecruitmentInterview
    {
        return RecruitmentInterview::with(self::INTERVIEW_RELATIONS)->findOrFail($interview->id);
    }
}
