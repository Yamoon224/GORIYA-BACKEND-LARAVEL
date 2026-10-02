<?php

namespace App\Services;

use App\Contracts\AiAnalysisServiceInterface;
use App\Enums\CandidateAssessmentStatus;
use App\Models\Candidature;
use App\Models\CandidateAssessment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestration de l'évaluation IA approfondie d'un candidat — un appel
 * AiAnalysisServiceInterface::assessCandidate() sur le dossier réel assemblé
 * par CandidatureCompatibilityService (voir AnthropicService).
 */
class CandidateAssessmentService
{
    public function __construct(
        private readonly AiAnalysisServiceInterface $aiAnalysisService,
        private readonly WebhookService $webhookService,
        private readonly CandidatureCompatibilityService $compatibilityService,
    ) {}

    public function find(string $candidatureId): ?CandidateAssessment
    {
        return CandidateAssessment::where('candidature_id', $candidatureId)->first();
    }

    /**
     * Régénère l'évaluation si elle existe déjà (une évaluation par
     * candidature — voir la contrainte unique sur candidature_id).
     */
    public function create(Candidature $candidature, ?string $exchangeNotes = null): CandidateAssessment
    {
        $jobOffer = $candidature->jobOffer;

        $assessment = CandidateAssessment::updateOrCreate(
            ['candidature_id' => $candidature->id],
            ['status' => CandidateAssessmentStatus::PENDING],
        );

        try {
            // Un seul appel IA, sur le dossier réel du candidat (CV, profil,
            // compétences, lettre, réponses) — le même que celui du score de
            // compatibilité de la carte. Auparavant l'IA ne recevait que le
            // nom, l'e-mail et l'intitulé du poste : des scores sans fondement.
            $job = $this->compatibilityService->jobData($candidature);
            $result = $job
                ? $this->aiAnalysisService->assessCandidate(
                    $this->compatibilityService->candidateData($candidature),
                    $job,
                    $exchangeNotes ?? '',
                )
                : null;

            // IA indisponible : échec assumé plutôt que des scores inventés.
            if ($result === null) {
                $assessment->update(['status' => CandidateAssessmentStatus::FAILED]);

                return $assessment->fresh();
            }

            $technicalScore = $result['technicalScore'];
            $culturalFitScore = $result['culturalFitScore'];
            $softSkillsScore = $result['softSkillsScore'];
            $overallScore = (int) round(($technicalScore + $culturalFitScore + $softSkillsScore) / 3);

            $assessment->update([
                'technical_score' => $technicalScore,
                'cultural_fit_score' => $culturalFitScore,
                'soft_skills_score' => $softSkillsScore,
                'overall_score' => $overallScore,
                'skills_test' => $result['questions'],
                'soft_skills_feedback' => $result['feedback'],
                'status' => CandidateAssessmentStatus::COMPLETED,
            ]);

            if ($companyId = $jobOffer?->company_id) {
                $this->webhookService->dispatch($companyId, 'candidate_assessment.completed', [
                    'candidatureId' => $candidature->id,
                    'assessmentId' => $assessment->id,
                    'overallScore' => $overallScore,
                    'technicalScore' => $technicalScore,
                    'culturalFitScore' => $culturalFitScore,
                    'softSkillsScore' => $softSkillsScore,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Candidate assessment failed: '.$e->getMessage());
            $assessment->update(['status' => CandidateAssessmentStatus::FAILED]);
        }

        return $assessment->fresh();
    }

    /**
     * Classe tous les candidats évalués pour une même offre — au moins 2
     * évaluations complètes requises, sinon comparaison sans objet.
     *
     * @return array<int, array{name: string, rank: int, reason: string}>
     */
    public function compareForJobOffer(string $jobOfferId): array
    {
        $assessed = Candidature::where('job_offer_id', $jobOfferId)
            ->whereHas('assessment', fn ($query) => $query->where('status', CandidateAssessmentStatus::COMPLETED))
            ->with('assessment')
            ->get();

        if ($assessed->count() < 2) {
            return [];
        }

        $candidates = $assessed
            ->map(fn (Candidature $c) => ['name' => $c->candidate_name, 'overallScore' => $c->assessment->overall_score])
            ->all();

        return $this->aiAnalysisService->compareCandidates($candidates)['ranking'];
    }
}
