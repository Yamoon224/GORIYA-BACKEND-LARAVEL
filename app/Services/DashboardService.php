<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\CVStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Resources\CandidatureResource;
use App\Http\Resources\JobOfferResource;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\CvAnalysis;
use App\Models\InterviewSession;
use App\Models\JobOffer;
use App\Models\User;
use Carbon\Carbon;
use Throwable;

/**
 * Mirroir de backend/src/dashboard/dashboard.service.ts. Utilisé par
 * DashboardController (préfixe public /dashboard) et AdminDashboardController
 * (préfixe /admin/dashboard, réponses enveloppées via ApiResponse::success).
 */
class DashboardService
{
    public function __construct(private readonly BookmarkService $bookmarkService) {}

    /**
     * Dispatch vers les stats scopées à l'utilisateur courant — un ADMIN
     * garde les stats globales existantes (parité stricte, aucune régression
     * pour /admin/dashboard/stats qui appelle getStats() directement).
     *
     * @return array<string, mixed>
     */
    public function getStatsForUser(User $user, ?string $start, ?string $end): array
    {
        return match ($user->role) {
            UserRole::USER => $this->getStudentStats($user),
            UserRole::ENTERPRISE => $this->getCompanyStats($user->company_id, $start, $end),
            default => $this->getStats($start, $end),
        };
    }

    /**
     * @return array{totalApplications: int, interviews: int, profileViews: int, savedJobs: int}
     */
    public function getStudentStats(User $user): array
    {
        return [
            'totalApplications' => Candidature::where('user_id', $user->id)->count(),
            // Aucun InterviewSession n'a de FK vers un utilisateur — non
            // scopable, stub à 0 (même convention que profileViews ci-dessous).
            'interviews' => 0,
            'profileViews' => 0,
            'savedJobs' => $this->bookmarkService->savedJobsCount($user->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompanyStats(?string $companyId, ?string $start, ?string $end): array
    {
        $range = $this->buildRange($start, $end);

        $applicationsQuery = Candidature::whereHas('jobOffer', fn ($q) => $q->where('company_id', $companyId));
        $applicationsReceived = $range
            ? (clone $applicationsQuery)->whereBetween('applied_date', [$range['start'], $range['end']])->count()
            : $applicationsQuery->count();

        $activeOffers = JobOffer::where('company_id', $companyId)->where('status', JobStatus::ACTIVE)->count();

        // Aucun tracking de vues ni de FK InterviewSession->utilisateur —
        // stubs à 0, même convention que profileViews/getProfileViews().
        $weeklyViews = 0;
        $interviewsScheduled = 0;

        $recentCandidates = Candidature::whereHas('jobOffer', fn ($q) => $q->where('company_id', $companyId))
            ->with(['user', 'jobOffer.company'])
            ->orderByDesc('applied_date')->take(5)->get();

        $topOffers = JobOffer::where('company_id', $companyId)->where('status', JobStatus::ACTIVE)
            ->with('company')->orderByDesc('applicants')->take(5)->get();

        $recentOffers = JobOffer::where('company_id', $companyId)
            ->with('company')->orderByDesc('publish_date')->take(5)->get();

        // Ordre fixe attendu par entreprise/app/(protected)/dashboard/content.tsx
        // (lecture positionnelle statsData[i].value) — ne pas réordonner.
        $statsData = [
            ['key' => 'activeOffers', 'label' => 'Annonces actives', 'value' => $activeOffers],
            ['key' => 'applicationsReceived', 'label' => 'Candidatures recues', 'value' => $applicationsReceived],
            ['key' => 'weeklyViews', 'label' => 'Vues cette semaine', 'value' => $weeklyViews],
            ['key' => 'interviewsScheduled', 'label' => 'Entretiens planifies', 'value' => $interviewsScheduled],
        ];

        return [
            'statsData' => $statsData,
            'chartData' => $this->getCompanyMonthlyTrend($companyId, 6),
            'lineChartData' => $this->getRecentOffersTrend(6, $companyId),
            'recentCandidates' => CandidatureResource::collection($recentCandidates),
            'topOffers' => JobOfferResource::collection($topOffers),
            'recentOffers' => JobOfferResource::collection($recentOffers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(?string $start, ?string $end): array
    {
        $range = $this->buildRange($start, $end);

        $applicationsInRange = $range
            ? Candidature::whereBetween('applied_date', [$range['start'], $range['end']])->count()
            : Candidature::count();

        $offersInRange = $range
            ? JobOffer::whereBetween('publish_date', [$range['start'], $range['end']])->count()
            : JobOffer::where('status', JobStatus::ACTIVE)->count();

        $activeStudents = User::where('role', UserRole::USER)->where('status', UserStatus::ACTIVE)->count();
        $partnerCompanies = Company::where('status', CompanyStatus::ACTIVE)->count();
        $analyzedCVs = CvAnalysis::where('status', CVStatus::COMPLETED)->count();
        $jobOffers = $offersInRange;
        $totalApplications = $applicationsInRange;
        $interviews = InterviewSession::count();

        $recentCandidates = Candidature::with(['user', 'jobOffer.company'])
            ->orderByDesc('applied_date')->take(5)->get();

        $topOffers = JobOffer::where('status', JobStatus::ACTIVE)
            ->with('company')->orderByDesc('applicants')->take(5)->get();

        $recentOffers = JobOffer::with('company')
            ->orderByDesc('publish_date')->take(5)->get();

        // Libellés français copiés tels quels depuis la source NestJS (sans
        // accents pour certains — pas une faute de frappe à corriger ici).
        $statsData = [
            ['key' => 'activeStudents', 'label' => 'Etudiants actifs', 'value' => $activeStudents],
            ['key' => 'partnerCompanies', 'label' => 'Entreprises partenaires', 'value' => $partnerCompanies],
            ['key' => 'jobOffers', 'label' => 'Offres publiees', 'value' => $jobOffers],
            ['key' => 'totalApplications', 'label' => 'Candidatures', 'value' => $totalApplications],
            ['key' => 'interviews', 'label' => 'Entretiens', 'value' => $interviews],
            ['key' => 'analyzedCVs', 'label' => 'CV analyses', 'value' => $analyzedCVs],
        ];

        return [
            'activeStudents' => $activeStudents,
            'partnerCompanies' => $partnerCompanies,
            'analyzedCVs' => $analyzedCVs,
            'jobOffers' => $jobOffers,
            'totalApplications' => $totalApplications,
            'interviews' => $interviews,
            // Jamais implémentés côté NestJS — zéros littéraux, pas un TODO.
            'profileViews' => 0,
            'savedJobs' => 0,
            'statsData' => $statsData,
            'chartData' => $this->getPerformanceData('month'),
            'lineChartData' => $this->getRecentOffersTrend(6),
            'recentCandidates' => CandidatureResource::collection($recentCandidates),
            'topOffers' => JobOfferResource::collection($topOffers),
            'recentOffers' => JobOfferResource::collection($recentOffers),
        ];
    }

    public function getRecentApplications(int $limit, ?User $user = null): mixed
    {
        $query = Candidature::with(['jobOffer.company', 'user'])->orderByDesc('applied_date');

        if ($user?->role === UserRole::USER) {
            $query->where('user_id', $user->id);
        } elseif ($user?->role === UserRole::ENTERPRISE) {
            $query->whereHas('jobOffer', fn ($q) => $q->where('company_id', $user->company_id));
        }

        return CandidatureResource::collection($query->take($limit)->get());
    }

    public function getRecommendedJobs(int $limit): mixed
    {
        $jobs = JobOffer::where('status', JobStatus::ACTIVE)
            ->with('company')
            ->orderByDesc('publish_date')
            ->take($limit)
            ->get();

        return JobOfferResource::collection($jobs);
    }

    /**
     * @return array{views: array<int, array{date: string, count: int}>, total: int}
     */
    public function getProfileViews(int $days): array
    {
        $now = now();
        $views = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $views[] = [
                'date' => $now->copy()->subDays($i)->format('Y-m-d'),
                'count' => 0,
            ];
        }

        // Aucun tracking de vues n'existe réellement dans la source — stub
        // fidèle, pas une fonctionnalité à construire.
        return ['views' => $views, 'total' => 0];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{start: Carbon, end: Carbon}|null
     */
    private function buildRange(?string $start, ?string $end): ?array
    {
        $startDate = $this->parseDate($start);
        $endDate = $this->parseDate($end);

        if (! $startDate && ! $endDate) {
            return null;
        }

        return [
            'start' => $startDate ?? Carbon::parse('1970-01-01T00:00:00.000Z'),
            'end' => $endDate ?? now(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPerformanceData(?string $period): array
    {
        $now = now();
        $data = [];

        if ($period === 'week') {
            $dayNames = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
            for ($i = 6; $i >= 0; $i--) {
                $dayStart = $now->copy()->subDays($i)->startOfDay();
                $dayEnd = $dayStart->copy()->endOfDay();
                $count = Candidature::whereBetween('applied_date', [$dayStart, $dayEnd])->count();
                $data[] = ['month' => $dayNames[$dayStart->dayOfWeek], 'value' => $count];
            }
        } else {
            $monthCount = $period === 'year' ? 12 : 6;
            $monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

            for ($i = $monthCount - 1; $i >= 0; $i--) {
                $monthStart = $now->copy()->startOfMonth()->subMonths($i);
                $monthEnd = $monthStart->copy()->endOfMonth();
                $count = Candidature::whereBetween('applied_date', [$monthStart, $monthEnd])->count();
                $data[] = [
                    'month' => $monthNames[$monthStart->month - 1],
                    'value' => $count,
                    'label' => "{$monthNames[$monthStart->month - 1]} {$monthStart->year}",
                ];
            }
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentOffersTrend(int $monthCount = 6, ?string $companyId = null): array
    {
        $now = now();
        // Liste non accentuée, différente de celle de getPerformanceData() —
        // incohérence réelle de la source, copiée telle quelle.
        $monthNames = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];
        $trend = [];

        for ($i = $monthCount - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->startOfMonth()->subMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $query = JobOffer::whereBetween('publish_date', [$monthStart, $monthEnd]);
            if ($companyId) {
                $query->where('company_id', $companyId);
            }
            $trend[] = [
                'month' => $monthNames[$monthStart->month - 1],
                'value' => $query->count(),
                'label' => "{$monthNames[$monthStart->month - 1]} {$monthStart->year}",
            ];
        }

        return $trend;
    }

    /**
     * Candidatures reçues par mois pour les offres d'une entreprise donnée —
     * équivalent company-scopé de getPerformanceData('month').
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCompanyMonthlyTrend(?string $companyId, int $monthCount = 6): array
    {
        $now = now();
        $monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
        $data = [];

        for ($i = $monthCount - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->startOfMonth()->subMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $count = Candidature::whereHas('jobOffer', fn ($q) => $q->where('company_id', $companyId))
                ->whereBetween('applied_date', [$monthStart, $monthEnd])
                ->count();
            $data[] = [
                'month' => $monthNames[$monthStart->month - 1],
                'value' => $count,
                'label' => "{$monthNames[$monthStart->month - 1]} {$monthStart->year}",
            ];
        }

        return $data;
    }
}
