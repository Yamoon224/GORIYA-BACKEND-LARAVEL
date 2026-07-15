<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\CandidatureStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
