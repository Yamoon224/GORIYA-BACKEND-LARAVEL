<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\CandidateAssessmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Évaluation IA approfondie d'un candidat pour une candidature donnée —
 * étend CvAnalysis/ScoringResult/MatchingResult (score global au-delà du
 * simple score CV) plutôt que de les remplacer. Voir
 * CandidateAssessmentService pour l'orchestration des appels IA.
 */
class CandidateAssessment extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'candidature_id',
        'technical_score',
        'soft_skills_score',
        'cultural_fit_score',
        'overall_score',
        'skills_test',
        'soft_skills_feedback',
        'report_path',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CandidateAssessmentStatus::class,
            'skills_test' => 'array',
        ];
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }
}
