<?php

namespace App\Services;

use App\Contracts\AiAnalysisServiceInterface;
use App\Enums\CandidateAssessmentStatus;
use App\Models\Candidature;
use App\Models\CandidateAssessment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestration de l'évaluation IA approfondie d'un candidat — combine des
 * appels existants (scoreCandidate, matchCandidateToJob) et deux nouveaux
 * (generateSkillsTest, analyzeSoftSkills) sur AiAnalysisServiceInterface,
 * sans introduire de nouvelle plomberie Claude (voir AnthropicService).
 */
class CandidateAssessmentService
{
    public function __construct(
        private readonly AiAnalysisServiceInterface $aiAnalysisService,
        private readonly WebhookService $webhookService,
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
            $scoring = $this->aiAnalysisService->scoreCandidate(
                $candidature->candidate_name,
                $candidature->candidate_email,
                $jobOffer?->title ?? '',
            );

            $matching = $this->aiAnalysisService->matchCandidateToJob(
                ['name' => $candidature->candidate_name, 'email' => $candidature->candidate_email],
                [
                    'title' => $jobOffer?->title ?? '',
                    'company' => $jobOffer?->company?->name ?? '',
                    'description' => $jobOffer?->description,
                ],
            );

            $skillsTest = $this->aiAnalysisService->generateSkillsTest($jobOffer?->title ?? '');
            $softSkills = $this->aiAnalysisService->analyzeSoftSkills($candidature->candidate_name, $exchangeNotes ?? '');

            $technicalScore = $scoring['overallScore'];
            $culturalFitScore = $matching['matchingScore'];
            $softSkillsScore = $softSkills['score'];
            $overallScore = (int) round(($technicalScore + $culturalFitScore + $softSkillsScore) / 3);

            $assessment->update([
                'technical_score' => $technicalScore,
                'cultural_fit_score' => $culturalFitScore,
                'soft_skills_score' => $softSkillsScore,
                'overall_score' => $overallScore,
                'skills_test' => $skillsTest['questions'],
                'soft_skills_feedback' => $softSkills['feedback'],
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
