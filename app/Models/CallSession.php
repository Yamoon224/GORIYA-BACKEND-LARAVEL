<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\CallSessionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * GORIYA Call — session de visioconférence adossée à une "room" lunion.meet
 * (voir LunionMeetService). `room_slug` est l'identifiant stable réutilisé
 * pour émettre des tokens de connexion (CallSessionService::issueJoinToken())
 * et pour router les webhooks entrants (LunionMeetWebhookController).
 */
class CallSession extends Model
{
    use Auditable, HasFactory, HasUuid;

    /** Durée retenue pour un appel sans entretien associé (celle de l'invitation .ics). */
    public const DEFAULT_DURATION_MINUTES = 60;

    /** Marge après la fin prévue : un appel qui déborde un peu n'est pas coupé. */
    public const GRACE_MINUTES = 30;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'host_id',
        'title',
        'room_slug',
        'room_ref',
        'scheduled_at',
        'invitees',
        'description',
        'status',
        'recording_url',
        'ended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CallSessionStatus::class,
            'scheduled_at' => 'datetime',
            'invitees' => 'array',
            'ended_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class);
    }

    /**
     * Invités qui voient la session dans leur liste sans en être l'hôte — le
     * candidat convoqué à un entretien de recrutement.
     */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'call_session_guests')->withTimestamps();
    }

    /** Entretien de recrutement tenu dans cette salle, s'il y en a un. */
    public function interview(): HasOne
    {
        return $this->hasOne(RecruitmentInterview::class);
    }

    /**
     * Moment à partir duquel la session est passée : début prévu (à défaut,
     * création) + durée + marge. Une session rejointe repart de la dernière
     * connexion, pour ne pas fermer une réunion commencée en retard ou qui se
     * prolonge.
     */
    public function expiresAt(): CarbonInterface
    {
        $start = $this->scheduled_at ?? $this->created_at ?? now();
        if ($this->status === CallSessionStatus::ACTIVE && $this->updated_at?->gt($start)) {
            $start = $this->updated_at;
        }

        return $start->copy()->addMinutes($this->durationMinutes() + self::GRACE_MINUTES);
    }

    public function isOverdue(): bool
    {
        return $this->status !== CallSessionStatus::ENDED && $this->expiresAt()->isPast();
    }

    public function durationMinutes(): int
    {
        return (int) ($this->interview?->duration_minutes ?: self::DEFAULT_DURATION_MINUTES);
    }
}
