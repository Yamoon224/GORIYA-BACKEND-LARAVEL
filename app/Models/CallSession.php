<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\CallSessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GORIYA Call — session de visioconférence adossée à une "room" lunion.meet
 * (voir LunionMeetService). `room_slug` est l'identifiant stable réutilisé
 * pour émettre des tokens de connexion (CallSessionService::issueJoinToken())
 * et pour router les webhooks entrants (LunionMeetWebhookController).
 */
class CallSession extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'host_id',
        'title',
        'room_slug',
        'room_ref',
        'scheduled_at',
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
}
