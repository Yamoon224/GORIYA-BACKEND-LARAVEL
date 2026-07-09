<?php

namespace App\Services\Admin;

use App\Contracts\AiAnalysisServiceInterface;
use App\Enums\CandidatureStatus;
use App\Enums\CVStatus;
use App\Enums\InterviewStatus;
use App\Enums\MatchingStatus;
use App\Enums\ScoringStatus;
use App\Http\Resources\CandidatureResource;
use App\Http\Resources\InterviewSessionResource;
use App\Http\Resources\MatchingResultResource;
use App\Http\Resources\ScoringResultResource;
use App\Repositories\Contracts\CandidatureRepositoryInterface;
use App\Repositories\Contracts\CvAnalysisRepositoryInterface;
use App\Repositories\Contracts\InterviewSessionRepositoryInterface;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use App\Repositories\Contracts\MatchingResultRepositoryInterface;
use App\Repositories\Contracts\ScoringResultRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirroir du sous-ensemble "actions" de backend/src/admin/admin-platform.service.ts
 * — une seule responsabilité : créer/muter un enregistrement réel à la
 * demande de l'admin (y compris les actions déclenchant un appel IA).
 * Extrait de l'ex-AdminPlatformService.
 */
class AdminActionService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly JobOfferRepositoryInterface $jobOfferRepository,
        private readonly CandidatureRepositoryInterface $candidatureRepository,
        private readonly InterviewSessionRepositoryInterface $interviewSessionRepository,
        private readonly MatchingResultRepositoryInterface $matchingResultRepository,
        private readonly ScoringResultRepositoryInterface $scoringResultRepository,
        private readonly CvAnalysisRepositoryInterface $cvAnalysisRepository,
        private readonly AiAnalysisServiceInterface $aiAnalysisService,
        private readonly NotificationService $notificationService,
    ) {}

    public function createJobApplication(string $userId, string $jobId): CandidatureResource
    {
        $candidate = $this->userRepository->find($userId);
        $jobOffer = $this->jobOfferRepository->find($jobId);

        if (! $candidate || ! $jobOffer) {
            abort(404, 'Candidat ou offre introuvable');
        }

        $candidature = $this->candidatureRepository->create([
            'candidate_name' => $candidate->name,
            'candidate_email' => $candidate->email,
            'status' => CandidatureStatus::EN_ATTENTE,
            'score' => 0,
            'applied_date' => now(),
            'user_id' => $candidate->id,
            'job_offer_id' => $jobOffer->id,
        ]);

        $jobOffer->increment('applicants');

        $fresh = $candidature->fresh(['user', 'jobOffer.company']);
        $this->notificationService->notifyNewApplication($fresh);

        return new CandidatureResource($fresh);
    }

    public function createInterviewSimulation(string $candidateId, string $position): InterviewSessionResource
    {
        $candidate = $this->userRepository->find($candidateId);
        if (! $candidate) {
            abort(404, 'Candidat introuvable');
        }

        $session = $this->interviewSessionRepository->create([
            'candidate_name' => $candidate->name,
            'candidate_email' => $candidate->email,
            'position' => $position,
            'duration' => 45,
            'score' => 0,
            'status' => InterviewStatus::ACTIVE,
            'start_time' => now(),
            'feedback' => '',
        ]);

        return new InterviewSessionResource($session);
    }

    public function triggerMatching(string $candidateId, string $jobOfferId): mixed
    {
        $candidate = $this->userRepository->find($candidateId);
        $jobOffer = $this->jobOfferRepository->find($jobOfferId);
        $jobOffer?->load('company');

        if (! $candidate || ! $jobOffer) {
            abort(404, 'Candidat ou offre introuvable');
        }

        $result = $this->aiAnalysisService->matchCandidateToJob(
            ['name' => $candidate->name, 'email' => $candidate->email],
            [
                'title' => $jobOffer->title,
                'company' => $jobOffer->company?->name ?? 'Entreprise',
                'description' => $jobOffer->description,
            ],
        );

        $match = $this->matchingResultRepository->create([
            'candidate_name' => $candidate->name,
            'candidate_email' => $candidate->email,
            'position' => $jobOffer->title,
            'company' => $jobOffer->company?->name ?? 'Entreprise',
            'matching_score' => $result['matchingScore'],
            'status' => MatchingStatus::NOUVEAU,
            'match_date' => now(),
        ]);

        return new MatchingResultResource($match);
    }

    public function analyzeScoring(string $candidateId, string $position): mixed
    {
        $candidate = $this->userRepository->find($candidateId);
        if (! $candidate) {
            abort(404, 'Candidat introuvable');
        }

        $aiResult = $this->aiAnalysisService->scoreCandidate($candidate->name, $candidate->email, $position);

        $result = $this->scoringResultRepository->create([
            'candidate_name' => $candidate->name,
            'candidate_email' => $candidate->email,
            'position' => $position,
            'overall_score' => $aiResult['overallScore'],
            'criteria' => $aiResult['criteria'],
            'analysis_date' => now(),
            'status' => ScoringStatus::COMPLETED,
        ]);

        return new ScoringResultResource($result);
    }

    public function createCvUpload(UploadedFile $file): array
    {
        return ['cvUrl' => $this->storeUploadedFile($file, 'uploads')];
    }

    public function analyzeCv(UploadedFile $file): array
    {
        $fileName = $this->storeUploadedFile($file, 'analysis');

        $entity = $this->cvAnalysisRepository->create([
            'filename' => $fileName,
            'analysis_score' => 0,
            'recommendations' => [],
            'upload_date' => now(),
            'status' => CVStatus::ANALYZING,
        ]);

        try {
            $result = $this->aiAnalysisService->analyzeCV($file->get(), $file->getMimeType(), $file->getClientOriginalName());

            $this->cvAnalysisRepository->update($entity, [
                'analysis_score' => $result['score'],
                'recommendations' => $result['recommendations'],
                'status' => CVStatus::COMPLETED,
            ]);

            return [
                'score' => $result['score'],
                'suggestions' => $result['recommendations'],
                'strengths' => $result['strengths'],
                'improvements' => $result['improvements'],
            ];
        } catch (Throwable $e) {
            $this->cvAnalysisRepository->update($entity, ['status' => CVStatus::FAILED]);
            throw $e;
        }
    }

    private function storeUploadedFile(UploadedFile $file, string $folder): string
    {
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $filename = Str::uuid().'.'.$extension;
        Storage::disk('public')->putFileAs("admin/{$folder}", $file, $filename);

        return "/storage/admin/{$folder}/{$filename}";
    }
}
