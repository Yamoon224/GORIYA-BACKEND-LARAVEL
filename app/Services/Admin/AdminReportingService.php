<?php

namespace App\Services\Admin;

use App\Enums\CandidatureStatus;
use App\Enums\CompanyStatus;
use App\Enums\CVStatus;
use App\Enums\EventStatus;
use App\Enums\InterviewStatus;
use App\Enums\JobStatus;
use App\Enums\MatchingStatus;
use App\Enums\ScoringStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Resources\InterviewSessionResource;
use App\Http\Resources\JobOfferResource;
use App\Http\Resources\PortfolioResource;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\CvAnalysis;
use App\Models\InterviewSession;
use App\Models\JobOffer;
use App\Models\Portfolio;
use App\Models\User;
use App\Repositories\Contracts\CalendarEventRepositoryInterface;
use App\Repositories\Contracts\CandidatureRepositoryInterface;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use App\Repositories\Contracts\CvAnalysisRepositoryInterface;
use App\Repositories\Contracts\InterviewSessionRepositoryInterface;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use App\Repositories\Contracts\PortfolioRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Concerns\BuildsCsv;
use App\Services\Concerns\PaginatesArrays;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Mirroir du sous-ensemble "reporting/stats" de backend/src/admin/
 * admin-platform.service.ts — une seule responsabilité : produire les
 * statistiques/projections en lecture seule consommées par le tableau de
 * bord admin. Extrait de l'ex-AdminPlatformService.
 *
 * Les totaux, moyennes et répartitions sont calculés par la base (COUNT, SUM,
 * GROUP BY) : charger chaque table en entier pour compter en PHP coûtait
 * plusieurs centaines de millisecondes par tuile dès quelques milliers de
 * lignes.
 */
class AdminReportingService
{
    use BuildsCsv, PaginatesArrays;

    private const DEFAULT_SCORING_CRITERIA = [
        ['name' => 'Competences', 'weight' => 40, 'score' => 0, 'maxScore' => 100],
        ['name' => 'Experience', 'weight' => 35, 'score' => 0, 'maxScore' => 100],
        ['name' => 'Communication', 'weight' => 25, 'score' => 0, 'maxScore' => 100],
    ];

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly CompanyRepositoryInterface $companyRepository,
        private readonly JobOfferRepositoryInterface $jobOfferRepository,
        private readonly CandidatureRepositoryInterface $candidatureRepository,
        private readonly CalendarEventRepositoryInterface $calendarEventRepository,
        private readonly PortfolioRepositoryInterface $portfolioRepository,
        private readonly CvAnalysisRepositoryInterface $cvAnalysisRepository,
        private readonly InterviewSessionRepositoryInterface $interviewSessionRepository,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | STUDENTS
    |--------------------------------------------------------------------------
    */
    public function getStudentStats(): array
    {
        $start = now()->startOfMonth();

        return [
            'total' => $this->userRepository->countByRole(UserRole::USER->value),
            'active' => $this->userRepository->countByRoleAndStatus(UserRole::USER->value, UserStatus::ACTIVE->value),
            'inactive' => $this->userRepository->countByRoleAndStatus(UserRole::USER->value, UserStatus::INACTIVE->value),
            'newThisMonth' => $this->userRepository->countByRoleCreatedBetween(UserRole::USER->value, $start, now()),
        ];
    }

    public function exportUsersCsv(): string
    {
        $users = $this->userRepository->findByRole(UserRole::USER->value);

        $rows = $users->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status->value,
            'registrationDate' => $user->registration_date->clone()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ])->all();

        return $this->toCsv($rows);
    }

    /*
    |--------------------------------------------------------------------------
    | COMPANIES
    |--------------------------------------------------------------------------
    */
    public function getCompanyStats(): array
    {
        $start = now()->startOfMonth();

        return [
            'total' => $this->companyRepository->count(),
            'active' => $this->companyRepository->countByStatus(CompanyStatus::ACTIVE->value),
            'inactive' => $this->companyRepository->countByStatus(CompanyStatus::INACTIVE->value),
            'newThisMonth' => $this->companyRepository->countCreatedBetween($start, now()),
        ];
    }

    public function getCompanySectors(): array
    {
        $sectors = Company::query()
            ->selectRaw('sector, COUNT(*) as total')
            ->groupBy('sector')
            ->orderByDesc('total')
            ->orderBy('sector')
            ->toBase()
            ->get();

        return $this->shares($sectors->map(fn ($row) => ['name' => $row->sector, 'count' => (int) $row->total])->all());
    }

    public function getCompanyJobs(string $companyId): mixed
    {
        $jobs = $this->jobOfferRepository->findByCompany($companyId);

        return JobOfferResource::collection($jobs);
    }

    /*
    |--------------------------------------------------------------------------
    | JOB OFFERS / CANDIDATURES
    |--------------------------------------------------------------------------
    */
    public function getJobOfferStats(): array
    {
        $byStatus = JobOffer::query()
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(applicants), 0) as applicants')
            ->groupBy('status')
            ->toBase()
            ->get()
            ->keyBy('status');

        return [
            'total' => (int) $byStatus->sum('total'),
            'active' => (int) ($byStatus[JobStatus::ACTIVE->value]->total ?? 0),
            'closed' => (int) ($byStatus[JobStatus::CLOSED->value]->total ?? 0),
            'draft' => (int) ($byStatus[JobStatus::DRAFT->value]->total ?? 0),
            'totalApplicants' => (int) $byStatus->sum('applicants'),
        ];
    }

    public function getJobOfferSectors(): array
    {
        $sectors = JobOffer::query()
            ->leftJoin('companies', 'companies.id', '=', 'job_offers.company_id')
            ->selectRaw('companies.sector as sector, COUNT(*) as total')
            ->groupBy('companies.sector')
            ->toBase()
            ->get();

        // Offres sans entreprise ou sans secteur : une seule ligne « Non classe ».
        $counts = [];
        foreach ($sectors as $row) {
            $name = $row->sector ?: 'Non classe';
            $counts[$name] = ($counts[$name] ?? 0) + (int) $row->total;
        }
        arsort($counts);

        return $this->shares(array_map(fn ($name, $count) => ['name' => $name, 'count' => $count], array_keys($counts), $counts));
    }

    public function getCandidatureStats(): array
    {
        $byStatus = $this->countBy(Candidature::query(), 'status');

        return [
            'total' => array_sum($byStatus),
            'enAttente' => $byStatus[CandidatureStatus::EN_ATTENTE->value] ?? 0,
            'enCours' => $byStatus[CandidatureStatus::EN_COURS->value] ?? 0,
            'approuvees' => $byStatus[CandidatureStatus::APPROUVEE->value] ?? 0,
            'rejetees' => $byStatus[CandidatureStatus::REJETEE->value] ?? 0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PLANNING
    |--------------------------------------------------------------------------
    */
    public function getPlanningStats(): array
    {
        $now = now();

        return [
            'totalEvents' => $this->calendarEventRepository->count(),
            'upcomingEvents' => $this->calendarEventRepository->countUpcoming($now, EventStatus::CANCELLED->value),
            'completedEvents' => $this->calendarEventRepository->countCompleted($now, EventStatus::CANCELLED->value),
            'cancelledEvents' => $this->calendarEventRepository->countByStatus(EventStatus::CANCELLED->value),
        ];
    }

    public function getPlanningEvents(?string $date): mixed
    {
        if (! $date) {
            return $this->calendarEventRepository->findAllOrdered();
        }

        $start = Carbon::parse($date)->startOfDay();
        $end = Carbon::parse($date)->endOfDay();

        return $this->calendarEventRepository->findBetween($start, $end);
    }

    public function getUpcomingPlanningEvents(int $limit): mixed
    {
        return $this->calendarEventRepository->findUpcoming(
            [EventStatus::CONFIRMED->value, EventStatus::PENDING->value],
            $limit,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PORTFOLIOS
    |--------------------------------------------------------------------------
    */
    public function getPortfolioStats(): array
    {
        $totals = Portfolio::query()
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(views), 0) as views, COALESCE(SUM(downloads), 0) as downloads, COALESCE(SUM(likes), 0) as likes')
            ->toBase()
            ->first();

        return [
            'totalPortfolios' => (int) $totals->total,
            'totalViews' => (int) $totals->views,
            'totalDownloads' => (int) $totals->downloads,
            'totalLikes' => (int) $totals->likes,
        ];
    }

    public function getFeaturedPortfolios(): mixed
    {
        return PortfolioResource::collection($this->portfolioRepository->findFeatured(6));
    }

    public function getPortfolioCategories(): array
    {
        $counts = [];

        // Seule la colonne `skills` est lue, sans instancier un modèle par portfolio.
        foreach (Portfolio::query()->whereNotNull('skills')->toBase()->pluck('skills') as $json) {
            foreach ((array) json_decode((string) $json, true) as $skill) {
                if (is_string($skill) || is_int($skill)) {
                    $counts[$skill] = ($counts[$skill] ?? 0) + 1;
                }
            }
        }

        return collect($counts)->map(fn ($count, $name) => ['name' => $name, 'count' => $count])->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | CV ANALYSIS
    |--------------------------------------------------------------------------
    */
    public function getCvAnalysisStats(): array
    {
        $byStatus = $this->countBy(CvAnalysis::query(), 'status');

        return [
            'totalAnalyzed' => array_sum($byStatus),
            'completed' => $byStatus[CVStatus::COMPLETED->value] ?? 0,
            'analyzing' => $byStatus[CVStatus::ANALYZING->value] ?? 0,
            'failed' => $byStatus[CVStatus::FAILED->value] ?? 0,
            'averageScore' => $this->averageOf(CvAnalysis::query(), 'analysis_score'),
        ];
    }

    public function getCvRecommendations(): array
    {
        $cvs = $this->cvAnalysisRepository->findRecent(20);
        $suggestions = $cvs->flatMap(fn (CvAnalysis $cv) => $cv->recommendations ?? [])->take(10);

        return $suggestions->map(fn ($suggestion) => [
            'category' => 'CV',
            'suggestion' => $suggestion,
            'impact' => 'medium',
        ])->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | INTERVIEW SIMULATION
    |--------------------------------------------------------------------------
    */
    public function getInterviewStats(): array
    {
        $today = now()->utc();
        $completed = InterviewSession::where('status', InterviewStatus::COMPLETED->value)->count();
        $satisfied = $completed
            ? InterviewSession::where('status', InterviewStatus::COMPLETED->value)->where('score', '>=', 70)->count()
            : 0;

        return [
            'todaySessions' => InterviewSession::whereBetween('start_time', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])->count(),
            'averageScore' => $this->averageOf(InterviewSession::query(), 'score'),
            'averageDuration' => $this->averageOf(InterviewSession::query(), 'duration').' min',
            'satisfaction' => $completed ? (int) round($satisfied / $completed * 100) : 0,
        ];
    }

    public function getActiveInterviewSessions(): mixed
    {
        $sessions = $this->interviewSessionRepository->findByStatus([
            InterviewStatus::ACTIVE->value,
            InterviewStatus::SCHEDULED->value,
        ]);

        return InterviewSessionResource::collection($sessions);
    }

    /**
     * @return array{data: array, meta: array}
     */
    public function getInterviewHistory(int $page, int $limit): array
    {
        // Pagination en base : seule la page demandée est lue (même forme
        // {data, meta} que paginateArray).
        $safeLimit = max(1, $limit);
        $safePage = max(1, $page);
        $query = InterviewSession::where('status', InterviewStatus::COMPLETED->value);
        $total = (clone $query)->count();

        return [
            'data' => $query->orderByDesc('start_time')->orderBy('id')
                ->skip(($safePage - 1) * $safeLimit)->take($safeLimit)->get()
                ->map(fn (InterviewSession $s) => (new InterviewSessionResource($s))->resolve())->all(),
            'meta' => [
                'total' => $total,
                'page' => $safePage,
                'limit' => $safeLimit,
                'totalPages' => (int) (ceil($total / $safeLimit) ?: 1),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MATCHING — projection sur les candidatures déjà scorées par
    | CandidatureCompatibilityService (candidatures.score > 0), plutôt que sur
    | la table matching_results qui ne reçoit jamais d'écriture en usage
    | normal (uniquement via l'action manuelle AdminActionService::
    | triggerMatching, sans bouton côté UI). Un rejet n'est pas un match actif
    | : exclu de la requête de base.
    |--------------------------------------------------------------------------
    */
    private function matchingQuery(): Builder
    {
        return Candidature::query()
            ->where('score', '>', 0)
            ->whereIn('status', [
                CandidatureStatus::EN_ATTENTE->value,
                CandidatureStatus::EN_COURS->value,
                CandidatureStatus::APPROUVEE->value,
            ]);
    }

    private function candidatureStatusToMatchingStatus(CandidatureStatus $status): MatchingStatus
    {
        return match ($status) {
            CandidatureStatus::EN_ATTENTE => MatchingStatus::NOUVEAU,
            CandidatureStatus::EN_COURS => MatchingStatus::EN_COURS,
            CandidatureStatus::APPROUVEE => MatchingStatus::FINALISE,
            CandidatureStatus::REJETEE => MatchingStatus::EN_COURS,
        };
    }

    public function matchingStatusToCandidatureStatus(string $status): CandidatureStatus
    {
        return match ($status) {
            MatchingStatus::NOUVEAU->value => CandidatureStatus::EN_ATTENTE,
            MatchingStatus::FINALISE->value => CandidatureStatus::APPROUVEE,
            default => CandidatureStatus::EN_COURS,
        };
    }

    public function toMatchingResultArray(Candidature $candidature): array
    {
        return [
            'id' => $candidature->id,
            'candidateName' => $candidature->candidate_name,
            'candidateEmail' => $candidature->candidate_email,
            'position' => $candidature->jobOffer?->title ?? '-',
            'company' => $candidature->jobOffer?->company?->name ?? '-',
            'matchingScore' => (int) $candidature->score,
            'status' => $this->candidatureStatusToMatchingStatus($candidature->status)->value,
            'matchDate' => $candidature->applied_date,
            'createdAt' => $candidature->created_at,
            'updatedAt' => $candidature->updated_at,
        ];
    }

    public function getMatchingStats(): array
    {
        $total = $this->matchingQuery()->count();
        $approved = $this->matchingQuery()->where('status', CandidatureStatus::APPROUVEE->value)->count();

        return [
            'totalMatches' => $total,
            'averageScore' => $this->averageOf($this->matchingQuery(), 'score'),
            'successRate' => $total ? (int) round($approved / $total * 100) : 0,
            'pendingMatches' => $total - $approved,
        ];
    }

    public function getMatchingAlgorithms(): array
    {
        $precision = $this->averageOf($this->matchingQuery(), 'score');
        $recall = max(0, $precision - 5);
        $f1Score = (int) round((2 * $precision * $recall) / max(1, $precision + $recall));

        return [
            'precision' => $precision,
            'recall' => $recall,
            'f1Score' => $f1Score,
            'algorithms' => [
                ['name' => 'Semantic Match', 'accuracy' => $precision],
                ['name' => 'Skills Scoring', 'accuracy' => $recall],
            ],
        ];
    }

    public function getMatchingActivity(): array
    {
        $matches = $this->matchingQuery()->with('jobOffer')->orderByDesc('updated_at')->limit(10)->get();

        return $matches->map(fn (Candidature $c) => [
            'id' => $c->id,
            'type' => 'matching',
            'message' => "{$c->candidate_name} matche sur ".($c->jobOffer->title ?? 'une offre'),
            'timestamp' => $c->updated_at,
        ])->all();
    }

    /**
     * @param  array{status?: ?string}  $filters
     */
    public function paginateMatching(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $query = $this->matchingQuery()->with('jobOffer.company');

        if (! empty($filters['status'])) {
            $query->where('status', $this->matchingStatusToCandidatureStatus($filters['status'])->value);
        }

        $paginator = $query->orderByDesc('applied_date')->paginate(max(1, $limit), ['*'], 'page', max(1, $page));
        $paginator->setCollection($paginator->getCollection()->map(fn (Candidature $c) => $this->toMatchingResultArray($c)));

        return $paginator;
    }

    /*
    |--------------------------------------------------------------------------
    | SCORING — même principe : projection sur candidatures.score plutôt que
    | sur scoring_results. « Scores Générés » = candidatures dont le score de
    | compatibilité a déjà été calculé ; les critères n'ont pas de répartition
    | réelle par catégorie (un seul score IA par candidature), donc chaque
    | critère pondéré affiche la même moyenne réelle plutôt qu'une
    | décomposition inventée.
    |--------------------------------------------------------------------------
    */
    public function toScoringResultArray(Candidature $candidature): array
    {
        $score = (int) $candidature->score;
        $criteriaDefs = Cache::rememberForever('admin:scoring_criteria', fn () => self::DEFAULT_SCORING_CRITERIA);

        return [
            'id' => $candidature->id,
            'candidateName' => $candidature->candidate_name,
            'candidateEmail' => $candidature->candidate_email,
            'position' => $candidature->jobOffer?->title ?? '-',
            'overallScore' => $score,
            'criteria' => collect($criteriaDefs)->map(fn (array $item) => [
                'name' => $item['name'],
                'weight' => $item['weight'],
                'score' => $score,
                'maxScore' => 100,
            ])->all(),
            'analysisDate' => $candidature->updated_at,
            'status' => $score > 0 ? ScoringStatus::COMPLETED->value : ScoringStatus::IN_PROGRESS->value,
            'createdAt' => $candidature->created_at,
            'updatedAt' => $candidature->updated_at,
        ];
    }

    public function findScoringResult(string $id): ?array
    {
        $candidature = Candidature::with('jobOffer')->find($id);

        return $candidature ? $this->toScoringResultArray($candidature) : null;
    }

    public function getScoringStats(): array
    {
        $total = Candidature::query()->count();
        $scored = Candidature::query()->where('score', '>', 0)->count();

        return [
            'generatedScores' => $scored,
            'averageScore' => $this->averageOf(Candidature::query()->where('score', '>', 0), 'score'),
            'accuracy' => $total ? (int) round($scored / $total * 100) : 0,
            'averageTime' => '3 min',
        ];
    }

    public function getScoringCriteria(): array
    {
        $criteria = Cache::rememberForever('admin:scoring_criteria', fn () => self::DEFAULT_SCORING_CRITERIA);
        $averageScore = $this->averageOf(Candidature::query()->where('score', '>', 0), 'score');

        return collect($criteria)->map(function (array $item) use ($averageScore) {
            $item['score'] = $averageScore;

            return $item;
        })->all();
    }

    public function updateScoringCriteria(array $criteria): array
    {
        Cache::forever('admin:scoring_criteria', $criteria);

        return $criteria;
    }

    public function getScoringPerformance(): array
    {
        // Une colonne, sans modèle dédié : le mois se lit sur les 7 premiers
        // caractères de la date (AAAA-MM), identique sur MySQL et SQLite.
        $scores = Candidature::query()->where('score', '>', 0)->orderBy('applied_date')->toBase()->get(['applied_date', 'score']);

        $trendData = $scores->groupBy(fn ($s) => substr((string) $s->applied_date, 0, 7))
            ->map(function ($items, $month) {
                $avg = $this->average($items->pluck('score')->all());

                return ['month' => $month, 'precision' => $avg, 'recall' => max(0, $avg - 5)];
            })->values()->all();

        $precision = $this->average($scores->pluck('score')->all());
        $recall = max(0, $precision - 5);
        $f1Score = (int) round((2 * $precision * $recall) / max(1, $precision + $recall));

        return compact('precision', 'recall', 'f1Score', 'trendData');
    }

    /**
     * @param  array{status?: ?string}  $filters
     */
    public function paginateScoring(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $query = Candidature::query()->with('jobOffer');
        $status = $filters['status'] ?? null;
        $query->where('score', $status === ScoringStatus::IN_PROGRESS->value ? '=' : '>', 0);

        $paginator = $query->orderByDesc('updated_at')->paginate(max(1, $limit), ['*'], 'page', max(1, $page));
        $paginator->setCollection($paginator->getCollection()->map(fn (Candidature $c) => $this->toScoringResultArray($c)));

        return $paginator;
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */
    /**
     * Nombre de lignes par valeur d'une colonne, en une requête.
     *
     * @return array<string, int>
     */
    private function countBy(Builder $query, string $column): array
    {
        return $query->selectRaw("{$column} as bucket, COUNT(*) as total")
            ->groupBy($column)
            ->toBase()
            ->pluck('total', 'bucket')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** Moyenne arrondie d'une colonne, une valeur absente comptant pour 0 (comme average()). */
    private function averageOf(Builder $query, string $column): int
    {
        $row = $query->selectRaw("COUNT(*) as total, COALESCE(SUM({$column}), 0) as amount")->toBase()->first();

        return $row && $row->total ? (int) round($row->amount / $row->total) : 0;
    }

    /**
     * @param  list<array{name: ?string, count: int}>  $rows
     * @return list<array{name: ?string, count: int, percentage: int}>
     */
    private function shares(array $rows): array
    {
        $total = max(1, array_sum(array_column($rows, 'count')));

        return array_map(fn (array $row) => $row + ['percentage' => (int) round($row['count'] / $total * 100)], array_values($rows));
    }

    private function average(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        return (int) round(array_sum(array_map(fn ($value) => $value ?? 0, $values)) / count($values));
    }
}
