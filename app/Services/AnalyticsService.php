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

/**
 * Mirroir de backend/src/analytics/analytics.service.ts. Utilisé par
 * AnalyticsController (préfixe public /analytics) et AdminAnalyticsController
 * (préfixe /admin/analytics, réponses enveloppées via ApiResponse::success).
 */
class AnalyticsService
{
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
            for ($i = 6; $i >= 0; $i--) {
                $dayStart = $now->copy()->subDays($i)->startOfDay();
                $dayEnd = $dayStart->copy()->endOfDay();
                $count = CvAnalysis::whereBetween('upload_date', [$dayStart, $dayEnd])->count();
                $data[] = ['month' => $dayNames[$dayStart->dayOfWeek], 'value' => $count];
            }
        } elseif ($period === 'month') {
            $weekLabels = ['S1', 'S2', 'S3', 'S4'];
            $monthStart = $now->copy()->startOfMonth();
            for ($i = 0; $i < 4; $i++) {
                $weekStart = $monthStart->copy()->addDays($i * 7);
                $weekEnd = $monthStart->copy()->addDays($i * 7 + 6)->endOfDay();
                $count = CvAnalysis::whereBetween('upload_date', [$weekStart, $weekEnd])->count();
                $data[] = ['month' => $weekLabels[$i], 'value' => $count];
            }
        } else {
            $monthCount = $period === 'year' ? 12 : 6;
            // Liste accentuée, identique à celle de DashboardService's
            // getPerformanceData() — mais distincte de getRecentOffersTrend()'s
            // liste non accentuée. Trois variantes différentes existent
            // dans la source, copiées telles quelles sans unification.
            $monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

            for ($i = $monthCount - 1; $i >= 0; $i--) {
                $monthStart = $now->copy()->startOfMonth()->subMonths($i);
                $monthEnd = $monthStart->copy()->endOfMonth();
                $count = CvAnalysis::whereBetween('upload_date', [$monthStart, $monthEnd])->count();
                $data[] = ['month' => $monthNames[$monthStart->month - 1], 'value' => $count];
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
