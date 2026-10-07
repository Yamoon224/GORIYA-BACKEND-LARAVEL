<?php

namespace App\Services;

use App\Enums\CVStatus;
use App\Enums\InterviewStatus;
use App\Enums\UserRole;
use App\Models\Candidature;
use App\Models\CvAnalysis;
use App\Models\InterviewSession;
use App\Models\MatchingResult;
use App\Models\User;
use App\Services\Concerns\CountsByPeriod;

/**
 * Mirroir de backend/src/analytics/analytics.service.ts. Utilisé par
 * AnalyticsController (préfixe public /analytics) et AdminAnalyticsController
 * (préfixe /admin/analytics, réponses enveloppées via ApiResponse::success).
 */
class AnalyticsService
{
    use CountsByPeriod;

    /**
     * @return array<string, mixed>
     */
    public function getAnalytics(): array
    {
        $rate = $this->matchingRate();

        return [
            'analyzedCVs' => CvAnalysis::where('status', CVStatus::COMPLETED)->count(),
            'successfulInterviews' => InterviewSession::where('status', InterviewStatus::COMPLETED)->count(),
            'matchingRate' => $rate,
            'totalApplications' => Candidature::count(),
            // Chaîne littérale côté source, jamais calculée réellement.
            'averageAnalysisTime' => '2h 30min',
            // 'month6' ne correspond à aucune des branches 'week'/'year' —
            // tombe dans la branche par défaut (6 mois), comportement copié
            // tel quel plutôt que la chaîne magique elle-même.
            'evolutionData' => $this->getEvolutionData('month6'),
            'activityDistribution' => $this->getActivityDistribution(),
        ];
    }

    public function getKPIs(): array
    {
        return [
            'registrations' => User::where('role', UserRole::USER)->count(),
            'matchingRate' => $this->matchingRate(),
            'cvAnalyzed' => CvAnalysis::where('status', CVStatus::COMPLETED)->count(),
            'interviewsDone' => InterviewSession::where('status', InterviewStatus::COMPLETED)->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEvolutionData(?string $period): array
    {
        $now = now();
        $data = [];

        if ($period === 'week') {
            $dayNames = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
            $counts = $this->countPerDay(CvAnalysis::query(), 'upload_date', $now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay());
            for ($i = 6; $i >= 0; $i--) {
                $dayStart = $now->copy()->subDays($i)->startOfDay();
                $data[] = ['month' => $dayNames[$dayStart->dayOfWeek], 'value' => $counts[$dayStart->format('Y-m-d')] ?? 0];
            }
        } elseif ($period === 'month') {
            // Quatre semaines glissantes depuis le 1er du mois : les jours sont
            // comptés en une requête, puis regroupés par tranche de sept.
            $weekLabels = ['S1', 'S2', 'S3', 'S4'];
            $monthStart = $now->copy()->startOfMonth();
            $counts = $this->countPerDay(CvAnalysis::query(), 'upload_date', $monthStart, $monthStart->copy()->addDays(27)->endOfDay());
            for ($i = 0; $i < 4; $i++) {
                $count = 0;
                for ($day = 0; $day < 7; $day++) {
                    $count += $counts[$monthStart->copy()->addDays($i * 7 + $day)->format('Y-m-d')] ?? 0;
                }
                $data[] = ['month' => $weekLabels[$i], 'value' => $count];
            }
        } else {
            $monthCount = $period === 'year' ? 12 : 6;
            // Liste accentuée, identique à celle de DashboardService's
            // getPerformanceData() — mais distincte de getRecentOffersTrend()'s
            // liste non accentuée. Trois variantes différentes existent
            // dans la source, copiées telles quelles sans unification.
            $monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

            $first = $now->copy()->startOfMonth()->subMonths($monthCount - 1);
            $counts = $this->countPerMonth(CvAnalysis::query(), 'upload_date', $first, $now->copy()->endOfMonth());
            for ($i = $monthCount - 1; $i >= 0; $i--) {
                $monthStart = $now->copy()->startOfMonth()->subMonths($i);
                $data[] = ['month' => $monthNames[$monthStart->month - 1], 'value' => $counts[$monthStart->format('Y-m')] ?? 0];
            }
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActivityDistribution(): array
    {
        return [
            ['name' => 'CVs analysés', 'value' => CvAnalysis::count(), 'color' => '#6366f1'],
            ['name' => 'Candidatures', 'value' => Candidature::count(), 'color' => '#22c55e'],
            ['name' => 'Entretiens', 'value' => InterviewSession::count(), 'color' => '#f59e0b'],
            ['name' => 'Matching', 'value' => MatchingResult::count(), 'color' => '#ec4899'],
        ];
    }

    /**
     * @return array<int, array{month: string, cv: int, entretiens: int}>
     */
    public function getMonthlyActivity(int $months = 6): array
    {
        $now = now();
        $monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
        $data = [];

        $first = $now->copy()->startOfMonth()->subMonths($months - 1);
        $last = $now->copy()->endOfMonth();
        $cvs = $this->countPerMonth(CvAnalysis::query(), 'upload_date', $first, $last);
        $interviews = $this->countPerMonth(InterviewSession::query(), 'created_at', $first, $last);

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->startOfMonth()->subMonths($i);
            $key = $monthStart->format('Y-m');

            $data[] = [
                'month' => $monthNames[$monthStart->month - 1],
                'cv' => $cvs[$key] ?? 0,
                'entretiens' => $interviews[$key] ?? 0,
            ];
        }

        return $data;
    }

    /**
     * @return array<int, array{name: string, value: int, color: string}>
     */
    public function getUserTypeDistribution(): array
    {
        $byRole = User::query()->selectRaw('role, COUNT(*) as total')->groupBy('role')->toBase()->pluck('total', 'role');

        return [
            ['name' => 'Candidats', 'value' => (int) ($byRole[UserRole::USER->value] ?? 0), 'color' => '#6366f1'],
            ['name' => 'Entreprises', 'value' => (int) ($byRole[UserRole::ENTERPRISE->value] ?? 0), 'color' => '#22c55e'],
            ['name' => 'Admins', 'value' => (int) ($byRole[UserRole::ADMIN->value] ?? 0), 'color' => '#f59e0b'],
        ];
    }

    public function exportReport(?string $period): string
    {
        $cvCount = CvAnalysis::where('status', CVStatus::COMPLETED)->count();
        $interviewCount = InterviewSession::where('status', InterviewStatus::COMPLETED)->count();
        $rate = $this->matchingRate();
        $kpis = $this->getKPIs();

        $lines = [
            "Rapport Analytics — Période : {$period}",
            'Généré le : '.now()->format('d/m/Y'),
            '',
            'KPIs',
            "Inscriptions,{$kpis['registrations']}",
            "CVs analysés,{$cvCount}",
            "Entretiens réalisés,{$interviewCount}",
            "Taux de matching,{$rate}%",
        ];

        return implode("\n", $lines);
    }

    private function matchingRate(): int
    {
        return (int) round(MatchingResult::avg('matching_score') ?? 0);
    }
}
