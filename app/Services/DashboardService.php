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

    public function getRecentApplications(int $limit): mixed
    {
        $candidatures = Candidature::with(['jobOffer.company', 'user'])
            ->orderByDesc('applied_date')
            ->take($limit)
            ->get();

        return CandidatureResource::collection($candidatures);
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
    private function getRecentOffersTrend(int $monthCount = 6): array
    {
        $now = now();
        // Liste non accentuée, différente de celle de getPerformanceData() —
        // incohérence réelle de la source, copiée telle quelle.
        $monthNames = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];
        $trend = [];

        for ($i = $monthCount - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->startOfMonth()->subMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $value = JobOffer::whereBetween('publish_date', [$monthStart, $monthEnd])->count();
            $trend[] = [
                'month' => $monthNames[$monthStart->month - 1],
                'value' => $value,
                'label' => "{$monthNames[$monthStart->month - 1]} {$monthStart->year}",
            ];
        }

        return $trend;
    }
}
