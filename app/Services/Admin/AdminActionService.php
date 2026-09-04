<?php

namespace App\Services\Admin;

use App\Contracts\AiAnalysisServiceInterface;
use App\Enums\CandidatureStatus;
use App\Enums\CVStatus;
use App\Enums\InterviewStatus;
use App\Enums\JobQuestionType;
use App\Enums\MatchingStatus;
use App\Enums\ScoringStatus;
use App\Http\Resources\CandidatureResource;
use App\Http\Resources\InterviewSessionResource;
use App\Http\Resources\MatchingResultResource;
use App\Http\Resources\ScoringResultResource;
use App\Models\JobOffer;
use App\Models\JobOfferQuestion;
use App\Models\UserResume;
use App\Repositories\Contracts\CandidatureRepositoryInterface;
use App\Repositories\Contracts\CvAnalysisRepositoryInterface;
use App\Repositories\Contracts\InterviewSessionRepositoryInterface;
use App\Repositories\Contracts\JobOfferRepositoryInterface;
use App\Repositories\Contracts\MatchingResultRepositoryInterface;
use App\Repositories\Contracts\ScoringResultRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    /**
     * @param  array{phone?: string|null, location?: string|null, coverLetter?: string|null, resumeId?: string|null, answers?: array<int, array{questionId: string, value: mixed}>}  $details
     *                                                                                                                                                                                         Corps du wizard de candidature (ApplyToJobRequest). Vide pour les
     *                                                                                                                                                                                         appels admin, qui créent une candidature sans passer par le wizard.
     */
    public function createJobApplication(string $userId, string $jobId, array $details = []): CandidatureResource
    {
        $candidate = $this->userRepository->find($userId);
        $jobOffer = $this->jobOfferRepository->find($jobId);

        if (! $candidate || ! $jobOffer) {
            abort(404, 'Candidat ou offre introuvable');
        }

        // Une seule candidature par couple (candidat, offre) : le bouton
        // "Postuler" est masqué côté front dès que l'offre est déjà postulée,
        // ce garde-fou couvre les appels directs / double-soumission.
        if ($this->candidatureRepository->existsForUserAndJob($candidate->id, $jobOffer->id)) {
            abort(409, 'Vous avez déjà postulé pour cette offre.');
        }

        // Validé AVANT la création : une question obligatoire laissée vide ne
        // doit pas laisser derrière elle une candidature à moitié écrite.
        $reponses = $this->prepareAnswers($jobOffer, $details['answers'] ?? []);
        $resumeId = $this->resolveResumeId($candidate->id, $details['resumeId'] ?? null);

        $candidature = DB::transaction(function () use ($candidate, $jobOffer, $details, $reponses, $resumeId) {
            $candidature = $this->candidatureRepository->create([
                'candidate_name' => $candidate->name,
                'candidate_email' => $candidate->email,
                'candidate_phone' => $this->valeurOuProfil($details['phone'] ?? null, $candidate->phone),
                'candidate_location' => $this->valeurOuProfil($details['location'] ?? null, $candidate->location),
                'cover_letter' => $this->valeurOuProfil($details['coverLetter'] ?? null, null),
                'resume_id' => $resumeId,
                'status' => CandidatureStatus::EN_ATTENTE,
                'score' => 0,
                'applied_date' => now(),
                'user_id' => $candidate->id,
                'job_offer_id' => $jobOffer->id,
            ]);

            foreach ($reponses as $reponse) {
                $candidature->answers()->create($reponse);
            }

            $jobOffer->increment('applicants');

            return $candidature;
        });

        $fresh = $candidature->fresh(['user', 'jobOffer.company', 'answers', 'resume']);
        $this->notificationService->notifyNewApplication($fresh);

        return new CandidatureResource($fresh);
    }

    /** Chaîne vide envoyée par un formulaire => on retombe sur la valeur du profil. */
    private function valeurOuProfil(?string $saisie, ?string $defaut): ?string
    {
        $saisie = $saisie === null ? '' : trim($saisie);

        return $saisie !== '' ? $saisie : $defaut;
    }

    /**
     * Un candidat ne peut joindre qu'un CV lui appartenant — sinon l'id d'un
     * CV deviné exposerait le fichier d'un autre utilisateur à l'entreprise.
     */
    private function resolveResumeId(string $userId, ?string $resumeId): ?string
    {
        if (! $resumeId) {
            return null;
        }

        $resume = UserResume::where('user_id', $userId)->find($resumeId);
        if (! $resume) {
            abort(422, 'Le CV sélectionné est introuvable dans votre bibliothèque.');
        }

        return $resume->id;
    }

    /**
     * Confronte les réponses reçues aux questions réellement configurées sur
     * l'offre : les questions inconnues sont ignorées, les obligatoires non
     * remplies rejettent la candidature.
     *
     * @param  array<int, array{questionId?: string, value?: mixed}>  $answers
     * @return list<array<string, mixed>> Lignes prêtes pour candidature_answers.
     */
    private function prepareAnswers(JobOffer $jobOffer, array $answers): array
    {
        $questions = $jobOffer->questions()->get();
        if ($questions->isEmpty()) {
            return [];
        }

        $parQuestion = [];
        foreach ($answers as $answer) {
            $questionId = $answer['questionId'] ?? null;
            if ($questionId) {
                $parQuestion[$questionId] = $answer['value'] ?? null;
            }
        }

        $lignes = [];
        foreach ($questions as $position => $question) {
            $valeurs = $this->normalizeAnswer($question, $parQuestion[$question->id] ?? null);

            if ($valeurs === []) {
                if ($question->required) {
                    abort(422, 'Merci de répondre à la question : '.$question->label);
                }

                continue;
            }

            $lignes[] = [
                'question_id' => $question->id,
                'question_label' => $question->label,
                'question_type' => $question->type->value,
                'value' => $valeurs,
                'position' => $position,
            ];
        }

        return $lignes;
    }

    /**
     * Normalise une réponse brute selon le type de la question. Toujours une
     * liste de chaînes (vide = pas de réponse), y compris pour les types à
     * valeur unique : la colonne `value` a un format unique à relire.
     *
     * @return list<string>
     */
    private function normalizeAnswer(JobOfferQuestion $question, mixed $brut): array
    {
        $options = is_array($question->options) ? $question->options : [];

        return match ($question->type) {
            JobQuestionType::MULTI_CHOICE => $this->assertDansOptions(
                $question,
                array_values(array_filter(
                    array_map(fn ($v) => trim((string) $v), is_array($brut) ? $brut : ($brut === null ? [] : [$brut])),
                    fn ($v) => $v !== ''
                )),
                $options,
            ),
            JobQuestionType::SINGLE_CHOICE => $this->assertDansOptions(
                $question,
                trim((string) (is_array($brut) ? ($brut[0] ?? '') : $brut)) === '' ? [] : [trim((string) (is_array($brut) ? $brut[0] : $brut))],
                $options,
            ),
            // Seul `false` explicite compte comme réponse négative : `null`
            // (question laissée vide) doit rester une non-réponse.
            JobQuestionType::BOOLEAN => $brut === null || $brut === '' ? [] : [filter_var($brut, FILTER_VALIDATE_BOOL) ? 'true' : 'false'],
            JobQuestionType::NUMBER => $this->assertNombre($question, is_array($brut) ? null : $brut),
            default => trim((string) (is_array($brut) ? implode(', ', $brut) : $brut)) === ''
                ? []
                : [trim((string) (is_array($brut) ? implode(', ', $brut) : $brut))],
        };
    }

    /**
     * @param  list<string>  $valeurs
     * @param  list<string>  $options
     * @return list<string>
     */
    private function assertDansOptions(JobOfferQuestion $question, array $valeurs, array $options): array
    {
        foreach ($valeurs as $valeur) {
            if (! in_array($valeur, $options, true)) {
                abort(422, 'Réponse invalide pour la question : '.$question->label);
            }
        }

        return $valeurs;
    }

    /**
     * @return list<string>
     */
    private function assertNombre(JobOfferQuestion $question, mixed $brut): array
    {
        $valeur = trim((string) $brut);
        if ($valeur === '') {
            return [];
        }

        if (! is_numeric($valeur)) {
            abort(422, 'La question "'.$question->label.'" attend un nombre.');
        }

        return [$valeur];
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
