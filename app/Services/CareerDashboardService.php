<?php

namespace App\Services;

use App\Enums\CandidatureStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Candidature;
use App\Models\Enrollment;
use App\Models\PublicProfile;
use App\Models\User;

/**
 * Tableau de bord carrière personnel (candidat) — agrège la progression
 * dans le temps plutôt que des stats instantanées (voir DashboardService
 * pour ces dernières, non touché ici : verrouillé par parité NestJS).
 *
 * Deux limitations héritées du schéma existant, déjà documentées ailleurs
 * (PublicProfileService, ChatService) :
 * - CvAnalysis n'a pas de user_id → pas d'historique de score CV possible.
 * - InterviewSession n'a pas de user_id → nombre d'entretiens non scopable
 *   (même stub à 0 que DashboardService::getStudentStats()).
 */
class CareerDashboardService
{
    public function forUser(User $user): array
    {
        return [
            'applications' => $this->applicationsSummary($user),
            'formations' => $this->formationsSummary($user),
            'profileViews' => 0, // aucun tracking de vues n'existe (voir DashboardService::getProfileViews())
            'interviews' => 0, // InterviewSession non scopable (voir docblock de la classe)
            'recommendations' => $this->recommendations($user),
        ];
    }

    /**
     * @return array{total: int, byStatus: array<string, int>, responseRate: float}
     */
    private function applicationsSummary(User $user): array
    {
        $candidatures = Candidature::where('user_id', $user->id)->get();
        $total = $candidatures->count();

        $byStatus = [];
        foreach (CandidatureStatus::cases() as $status) {
            $byStatus[$status->value] = $candidatures->where('status', $status)->count();
        }

        $responded = $total - ($byStatus[CandidatureStatus::EN_ATTENTE->value] ?? 0);
        $responseRate = $total > 0 ? round($responded / $total * 100, 1) : 0.0;

        return ['total' => $total, 'byStatus' => $byStatus, 'responseRate' => $responseRate];
    }

    /**
     * @return array{enrolled: int, completed: int, inProgress: int}
     */
    private function formationsSummary(User $user): array
    {
        $enrollments = Enrollment::where('user_id', $user->id)->get();

        return [
            'enrolled' => $enrollments->count(),
            'completed' => $enrollments->where('status', EnrollmentStatus::COMPLETED)->count(),
            'inProgress' => $enrollments->where('status', EnrollmentStatus::IN_PROGRESS)->count(),
        ];
    }

    /**
     * Recommandations à base de règles simples sur les données déjà
     * calculées — pas d'appel IA ici (aucun signal texte à analyser, juste
     * des compteurs), contrairement aux autres services Anthropic* de la
     * plateforme.
     *
     * @return array<int, string>
     */
    private function recommendations(User $user): array
    {
        $recommendations = [];

        $applications = $this->applicationsSummary($user);
        if ($applications['total'] === 0) {
            $recommendations[] = 'Postulez à votre première offre pour commencer à suivre votre progression.';
        } elseif ($applications['responseRate'] < 30) {
            $recommendations[] = 'Votre taux de réponse est bas — essayez de générer un Pitch Goriya pour vous démarquer.';
        }

        if ($this->formationsSummary($user)['enrolled'] === 0) {
            $recommendations[] = 'Explorez la Section Formation pour renforcer votre profil avec une nouvelle compétence.';
        }

        $profile = PublicProfile::where('user_id', $user->id)->where('is_public', true)->first();
        if (! $profile) {
            $recommendations[] = 'Publiez votre Profil Public GORIYA pour augmenter votre visibilité auprès des recruteurs.';
        }

        return $recommendations !== [] ? $recommendations : ['Continuez sur cette lancée, votre profil progresse bien !'];
    }
}
