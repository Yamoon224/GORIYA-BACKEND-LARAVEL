<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\JobQuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Question de présélection attachée à une offre d'emploi. Configurée par
 * l'entreprise depuis /poster-offre, répondue par le candidat à l'étape 2 du
 * wizard de candidature.
 */
class JobOfferQuestion extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'job_offer_id',
        'label',
        'type',
        'options',
        'required',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JobQuestionType::class,
            'options' => 'array',
            'required' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function jobOffer(): BelongsTo
    {
        return $this->belongsTo(JobOffer::class);
    }
}
