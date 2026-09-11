<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\InterviewRecommendation;
use App\Enums\RecruitmentInterviewStatus;
use App\Enums\RecruitmentInterviewType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entretien planifié avec un candidat, puis son compte rendu (note sur 5,
 * recommandation, commentaires).
 */
class RecruitmentInterview extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'candidature_id',
        'type',
        'scheduled_at',
        'duration_minutes',
        'location',
        'meeting_url',
        'interviewers',
        'description',
        'status',
        'rating',
        'recommendation',
        'feedback',
        'candidate_notified_at',
        'created_by',
        'outcome_by',
        'outcome_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RecruitmentInterviewType::class,
            'status' => RecruitmentInterviewStatus::class,
            'recommendation' => InterviewRecommendation::class,
            // Immuable : l'heure de fin se calcule sans décaler l'heure de début.
            'scheduled_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'rating' => 'integer',
            'candidate_notified_at' => 'datetime',
            'outcome_at' => 'datetime',
        ];
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function outcomeAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outcome_by');
    }
}
