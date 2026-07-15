<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Présence effective dans une CallSession, alimentée par les webhooks
 * entrants lunion.meet (participant.joined/participant.left) — voir
 * LunionMeetWebhookController. Reflète l'historique réel de connexion, pas
 * une simple liste d'invités.
 */
class CallParticipant extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'call_session_id',
        'identity',
        'name',
        'joined_at',
        'left_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}
