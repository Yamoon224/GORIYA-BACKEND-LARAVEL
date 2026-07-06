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
use App\Models\CvAnalysis;
use App\Models\InterviewSession;
use App\Models\JobOffer;
use App\Models\ScoringResult;
use App\Models\User;
use App\Repositories\Contracts\CalendarEventRepositoryInterface;
use App\Repositories\Contracts\CandidatureRepositoryInterface;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use App\Repositories\Contracts\CvAnalysisRepositoryInterface;
use App\Repositories\Contracts\InterviewSessionRepositoryInterface;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use App\Repositories\Contracts\MatchingResultRepositoryInterface;
use App\Repositories\Contracts\PortfolioRepositoryInterface;
use App\Repositories\Contracts\ScoringResultRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Concerns\BuildsCsv;
use App\Services\Concerns\PaginatesArrays;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Mirroir du sous-ensemble "reporting/stats" de backend/src/admin/
 * admin-platform.service.ts — une seule responsabilité : produire les
 * statistiques/projections en lecture seule consommées par le tableau de
 * bord admin. Extrait de l'ex-AdminPlatformService.
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
        private readonly MatchingResultRepositoryInterface $matchingResultRepository,
        private readonly ScoringResultRepositoryInterface $scoringResultRepository,
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
        $companies = $this->companyRepository->all();
        $total = max(1, $companies->count());

        return $companies->groupBy('sector')->map(function ($group, $sector) use ($total) {
            $count = $group->count();

            return ['name' => $sector, 'count' => $count, 'percentage' => (int) round($count / $total * 100)];
        })->values()->all();
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
        $offers = $this->jobOfferRepository->all();

        return [
            'total' => $offers->count(),
            'active' => $offers->where('status', JobStatus::ACTIVE)->count(),
            'closed' => $offers->where('status', JobStatus::CLOSED)->count(),
            'draft' => $offers->where('status', JobStatus::DRAFT)->count(),
            'totalApplicants' => (int) $offers->sum('applicants'),
        ];
    }

    public function getJobOfferSectors(): array
    {
        $offers = $this->jobOfferRepository->findAllWithCompany();
        $total = max(1, $offers->count());

        return $offers->groupBy(fn (JobOffer $offer) => $offer->company?->sector ?: 'Non classe')
            ->map(function ($group, $sector) use ($total) {
                $count = $group->count();

                return ['name' => $sector, 'count' => $count, 'percentage' => (int) round($count / $total * 100)];
            })->values()->all();
    }

    public function getCandidatureStats(): array
    {
        return [
            'total' => $this->candidatureRepository->count(),
            'enAttente' => $this->candidatureRepository->countByStatus(CandidatureStatus::EN_ATTENTE->value),
            'enCours' => $this->candidatureRepository->countByStatus(CandidatureStatus::EN_COURS->value),
            'approuvees' => $this->candidatureRepository->countByStatus(CandidatureStatus::APPROUVEE->value),
            'rejetees' => $this->candidatureRepository->countByStatus(CandidatureStatus::REJETEE->value),
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
        $portfolios = $this->portfolioRepository->all();

        return [
            'totalPortfolios' => $portfolios->count(),
            'totalViews' => (int) $portfolios->sum('views'),
            'totalDownloads' => (int) $portfolios->sum('downloads'),
            'totalLikes' => (int) $portfolios->sum('likes'),
        ];
    }

    public function getFeaturedPortfolios(): mixed
    {
        return PortfolioResource::collection($this->portfolioRepository->findFeatured(6));
    }

    public function getPortfolioCategories(): array
    {
        $counts = [];

        foreach ($this->portfolioRepository->all() as $portfolio) {
            foreach ($portfolio->skills ?? [] as $skill) {
                $counts[$skill] = ($counts[$skill] ?? 0) + 1;
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
        $cvs = $this->cvAnalysisRepository->all();

        return [
            'totalAnalyzed' => $cvs->count(),
            'completed' => $cvs->where('status', CVStatus::COMPLETED)->count(),
            'analyzing' => $cvs->where('status', CVStatus::ANALYZING)->count(),
            'failed' => $cvs->where('status', CVStatus::FAILED)->count(),
            'averageScore' => $this->average($cvs->pluck('analysis_score')->all()),
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
        $sessions = $this->interviewSessionRepository->all();
        $today = now()->utc()->format('Y-m-d');
        $completed = $sessions->where('status', InterviewStatus::COMPLETED);

        return [
            'todaySessions' => $sessions->filter(fn (InterviewSession $s) => $s->start_time->clone()->utc()->format('Y-m-d') === $today)->count(),
            'averageScore' => $this->average($sessions->pluck('score')->all()),
            'averageDuration' => $this->average($sessions->pluck('duration')->all()).' min',
            'satisfaction' => $completed->count()
                ? (int) round($completed->filter(fn (InterviewSession $s) => ($s->score ?? 0) >= 70)->count() / $completed->count() * 100)
                : 0,
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
        $sessions = $this->interviewSessionRepository->findCompletedOrderedByStartTime();
        $resolved = $sessions->map(fn (InterviewSession $s) => (new InterviewSessionResource($s))->resolve())->all();

        return $this->paginateArray($resolved, $page, $limit);
    }

    /*
    |--------------------------------------------------------------------------
    | MATCHING
    |--------------------------------------------------------------------------
    */
    public function getMatchingStats(): array
    {
        $matches = $this->matchingResultRepository->all();
        $finalized = $matches->where('status', MatchingStatus::FINALISE)->count();

        return [
            'totalMatches' => $matches->count(),
            'averageScore' => $this->average($matches->pluck('matching_score')->all()),
            'successRate' => $matches->count() ? (int) round($finalized / $matches->count() * 100) : 0,
            'pendingMatches' => $matches->where('status', '!=', MatchingStatus::FINALISE)->count(),
        ];
    }

    public function getMatchingAlgorithms(): array
    {
        $precision = $this->average($this->matchingResultRepository->all()->pluck('matching_score')->all());
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
        $matches = $this->matchingResultRepository->findRecent(10);

        return $matches->map(fn ($m) => [
            'id' => $m->id,
            'type' => 'matching',
            'message' => "{$m->candidate_name} matche sur {$m->position}",
            'timestamp' => $m->match_date->clone()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ])->all();
    }

    /*
    |--------------------------------------------------------------------------
    | SCORING
    |--------------------------------------------------------------------------
    */
    public function getScoringStats(): array
    {
        $scores = $this->scoringResultRepository->all();

        return [
            'generatedScores' => $scores->count(),
            'averageScore' => $this->average($scores->pluck('overall_score')->all()),
            'accuracy' => $scores->count()
                ? (int) round($scores->where('status', ScoringStatus::COMPLETED)->count() / $scores->count() * 100)
                : 0,
            'averageTime' => '3 min',
        ];
    }

    public function getScoringCriteria(): array
    {
        $scores = $this->scoringResultRepository->findRecent(10);
        $criteria = Cache::rememberForever('admin:scoring_criteria', fn () => self::DEFAULT_SCORING_CRITERIA);

        if ($scores->isEmpty()) {
            return $criteria;
        }

        return collect($criteria)->map(function (array $item) use ($scores) {
            $item['score'] = (int) round($this->average(
                $scores->map(fn (ScoringResult $score) => $this->extractCriterionScore($score->criteria, $item['name']))->all()
            ));

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
        $scores = $this->scoringResultRepository->findAllOrderedByAnalysisDate();

        $trendData = $scores->groupBy(fn (ScoringResult $s) => $s->analysis_date->format('Y-m'))
            ->map(function ($items, $month) {
                $avg = $this->average($items->pluck('overall_score')->all());

                return ['month' => $month, 'precision' => $avg, 'recall' => max(0, $avg - 5)];
            })->values()->all();

        $precision = $this->average($scores->pluck('overall_score')->all());
        $recall = max(0, $precision - 5);
        $f1Score = (int) round((2 * $precision * $recall) / max(1, $precision + $recall));

        return compact('precision', 'recall', 'f1Score', 'trendData');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */
    private function average(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        return (int) round(array_sum(array_map(fn ($value) => $value ?? 0, $values)) / count($values));
    }

    private function extractCriterionScore(mixed $criteria, string $name): float
    {
        if (is_array($criteria) && array_is_list($criteria)) {
            foreach ($criteria as $item) {
                if (($item['name'] ?? null) === $name) {
                    return (float) ($item['score'] ?? 0);
                }
            }

            return 0;
        }

        if (is_array($criteria) && array_key_exists($name, $criteria) && is_numeric($criteria[$name])) {
            return (float) $criteria[$name];
        }

        return 0;
    }
}
