<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\JobQuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réponse d'un candidat à une question de l'offre. Le libellé et le type sont
 * recopiés à la soumission pour que la candidature reste lisible même si
 * l'entreprise réécrit ou supprime la question ensuite.
 */
class CandidatureAnswer extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'candidature_id',
        'question_id',
        'question_label',
        'question_type',
        'value',
        'position',
    ];

    /**
     * Les réponses sont des données personnelles saisies par le candidat :
     * inutile de les dupliquer dans audit_logs.
     *
     * @var list<string>
     */
    protected $auditExcludes = [
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_type' => JobQuestionType::class,
            'value' => 'array',
            'position' => 'integer',
        ];
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(JobOfferQuestion::class, 'question_id');
    }
}
