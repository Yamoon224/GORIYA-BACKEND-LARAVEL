<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\SurveyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Enquête de satisfaction/évaluation anonyme pour les employés d'une
 * entreprise — voir EmployeeSurveyService::stats() pour l'agrégation
 * (jamais de réponse individuelle exposée à l'entreprise).
 */
class EmployeeSurvey extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'created_by',
        'title',
        'description',
        'questions',
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
            'status' => SurveyStatus::class,
            'questions' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class, 'survey_id');
    }
}
