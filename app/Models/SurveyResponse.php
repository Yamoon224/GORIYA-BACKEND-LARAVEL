<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réponse anonyme (vis-à-vis de l'entreprise) à un EmployeeSurvey — voir la
 * note sur user_id dans la migration create_survey_responses_table.
 * Pas de trait Auditable ici : l'audit log capturerait précisément la
 * donnée (qui a répondu quoi) que ce modèle existe pour ne jamais exposer.
 */
class SurveyResponse extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'survey_id',
        'user_id',
        'answers',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(EmployeeSurvey::class, 'survey_id');
    }
}
