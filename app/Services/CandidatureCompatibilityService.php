<?php

namespace App\Services;

use App\Contracts\AiAnalysisServiceInterface;
use App\Models\Candidature;
use App\Models\CvProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Score « Compatibilité IA » d'une candidature (colonne `candidatures.score`,
 * affichée sur la page Candidatures de l'espace entreprise). La colonne vaut
 * 0 tant que rien n'a été calculé — c'est la convention déjà lue par le front
 * (`score > 0`), d'où le plancher à 1 côté IA.
 *
 * Calculé une première fois juste après le dépôt de la candidature (voir
 * AdminActionService::createJobApplication), et à la demande depuis la page
 * Candidatures pour celles qui n'ont pas encore de score.
 */
class CandidatureCompatibilityService
{
    public function __construct(private readonly AiAnalysisServiceInterface $aiAnalysisService) {}

    /**
     * Calcule et enregistre le score. `null` (et colonne inchangée) quand
     * l'IA est indisponible : on préfère « pas de score » à un score inventé.
     */
    public function compute(Candidature $candidature): ?int
    {
        $job = $this->jobData($candidature);

        if (! $job) {
            return null;
        }

        $score = $this->aiAnalysisService->scoreCompatibility($this->candidateData($candidature), $job);

        if ($score !== null) {
            $candidature->update(['score' => $score]);
        }

        return $score;
    }

    /**
     * Offre visée, telle que soumise à l'IA — partagé avec
     * CandidateAssessmentService pour que l'évaluation approfondie juge le
     * même dossier que le score de la carte.
     *
     * @return array<string, mixed>|null
     */
    public function jobData(Candidature $candidature): ?array
    {
        $candidature->loadMissing('jobOffer.company');
        $jobOffer = $candidature->jobOffer;

        if (! $jobOffer) {
            return null;
        }

        return [
            'title' => (string) $jobOffer->title,
            'company' => $jobOffer->company?->name,
            'description' => $jobOffer->description,
            'requirements' => array_values(array_filter((array) $jobOffer->requirements, 'is_string')),
            'experience' => $jobOffer->experience?->value,
            'location' => $jobOffer->location,
        ];
    }

    /**
     * Dossier réel du candidat : titre, compétences, profil, CV joint, lettre
     * et réponses aux questions de l'offre.
     *
     * @return array<string, mixed>
     */
    public function candidateData(Candidature $candidature): array
    {
        $candidature->loadMissing(['user.portfolios', 'user.cv', 'answers', 'resume']);
        $user = $candidature->user;

        $cvSkills = collect(data_get($user?->cv?->data, 'competences', []))
            ->map(fn ($entree) => is_array($entree) ? ($entree['nom'] ?? null) : $entree);

        $skills = collect($user?->portfolios?->pluck('skills')->flatten() ?? [])
            ->merge($cvSkills)
            ->filter(fn ($nom) => is_string($nom) && trim($nom) !== '')
            ->unique()
            ->take(30)
            ->values()
            ->all();

        // Profil validé après analyse de CV, à défaut le brouillon du créateur
        // de CV : passés tels quels (JSON), l'IA sait lire les deux formes.
        $profile = $user
            ? (CvProfile::query()->where('user_id', $user->id)->value('data') ?? $user->cv?->data)
            : null;

        return [
            'title' => $user?->title,
            'skills' => $skills,
            'profile' => match (true) {
                is_string($profile) => $profile,
                is_array($profile) && $profile !== [] => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                default => null,
            },
            'coverLetter' => $candidature->cover_letter,
            'answers' => $candidature->answers
                ->map(fn ($answer) => [
                    'question' => (string) $answer->question_label,
                    'answer' => implode(', ', array_map('strval', (array) ($answer->value ?? []))),
                ])
                ->all(),
            'resume' => $this->resumeFile($candidature),
        ];
    }

    /**
     * @return array{binary: string, mimeType: string, name: string}|null
     */
    private function resumeFile(Candidature $candidature): ?array
    {
        $resume = $candidature->resume;

        if (! $resume?->path) {
            return null;
        }

        try {
            // Même emplacement que UserResumeService (disque public, /resumes).
            $binary = Storage::disk('public')->get('resumes/'.basename($resume->path));
        } catch (Throwable $e) {
            Log::warning('Compatibility: CV illisible — '.$e->getMessage());
            $binary = null;
        }

        return $binary
            ? ['binary' => $binary, 'mimeType' => (string) $resume->mime_type, 'name' => (string) $resume->name]
            : null;
    }
}
