<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\CandidatureStatus;
use App\Enums\RecruitmentStage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candidature extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'candidate_name',
        'candidate_email',
        'status',
        'score',
        'applied_date',
        'user_id',
        'job_offer_id',
        'pitch_id',
        'candidate_phone',
        'candidate_location',
        'cover_letter',
        'resume_id',
        'pipeline_stage',
        'stage_changed_at',
        'rejection_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CandidatureStatus::class,
            'applied_date' => 'datetime',
            'pipeline_stage' => RecruitmentStage::class,
            'stage_changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobOffer(): BelongsTo
    {
        return $this->belongsTo(JobOffer::class, 'job_offer_id');
    }

    public function pitch(): BelongsTo
    {
        return $this->belongsTo(Pitch::class);
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(CandidateAssessment::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CandidatureAnswer::class)->orderBy('position');
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(UserResume::class, 'resume_id');
    }

    /** Fiche employé créée à partir de cette candidature (Services RH), le cas échéant. */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(RecruitmentStageEvent::class)->orderBy('created_at');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(RecruitmentInterview::class)->orderBy('scheduled_at');
    }

    public function recruitmentNotes(): HasMany
    {
        return $this->hasMany(RecruitmentNote::class)->orderByDesc('created_at');
    }

    /**
     * Étape enregistrée par le pipeline tant qu'elle correspond au statut
     * public ; sinon — statut changé depuis la page Candidatures, ou fiche
     * employé supprimée — l'étape que ce statut implique.
     */
    public function recordedStage(): RecruitmentStage
    {
        if ($this->hasConsistentStage()) {
            return $this->pipeline_stage;
        }

        return RecruitmentStage::fromCandidatureStatus($this->status ?? CandidatureStatus::EN_ATTENTE);
    }

    /** Étape affichée : « Embauché » dès qu'une fiche employé existe. */
    public function currentStage(): RecruitmentStage
    {
        return $this->employee ? RecruitmentStage::HIRED : $this->recordedStage();
    }

    /** Depuis quand le candidat est à son étape actuelle. */
    public function stageSince(): ?CarbonInterface
    {
        if ($this->employee) {
            return $this->employee->created_at;
        }
        if ($this->hasConsistentStage()) {
            return $this->stage_changed_at;
        }

        return $this->status === CandidatureStatus::EN_ATTENTE || $this->status === null
            ? $this->applied_date
            : $this->updated_at;
    }

    private function hasConsistentStage(): bool
    {
        $stage = $this->pipeline_stage;

        return $stage instanceof RecruitmentStage
            && $stage !== RecruitmentStage::HIRED
            && $stage->candidatureStatus() === $this->status;
    }
}
