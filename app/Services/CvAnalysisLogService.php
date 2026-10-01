<?php

namespace App\Services;

use App\Enums\CVStatus;
use App\Models\CvAnalysis;

/**
 * Journal des analyses de CV réellement effectuées. L'analyse elle-même
 * tourne côté standard (route Next /api/analyse-cv) et ne passe par le
 * backend que pour consommer le quota : sans cette trace, la table
 * `cv_analysis` restait vide et les tableaux de bord admin (CV analysés,
 * évolution, activité mensuelle) n'avaient rien de réel à compter.
 */
class CvAnalysisLogService
{
    public const FEATURE_KEY = 'cv_analysis';

    /**
     * À appeler quand une tentative `cv_analysis` vient d'être consommée :
     * le quota n'est débité qu'après une analyse réussie.
     */
    public function record(mixed $filename, mixed $score, mixed $recommendations = null): CvAnalysis
    {
        $name = is_string($filename) ? trim($filename) : '';

        return CvAnalysis::create([
            'filename' => $name !== '' ? mb_substr($name, 0, 255) : 'CV',
            'analysis_score' => is_numeric($score) ? max(0, min(100, (int) $score)) : 0,
            // Jamais null : le backoffice lit `recommendations.length`.
            'recommendations' => collect(is_array($recommendations) ? $recommendations : [])
                ->filter(fn ($r) => is_string($r) && trim($r) !== '')
                ->map(fn (string $r) => mb_substr(trim($r), 0, 500))
                ->take(10)->values()->all(),
            'upload_date' => now(),
            'status' => CVStatus::COMPLETED,
        ]);
    }
}
