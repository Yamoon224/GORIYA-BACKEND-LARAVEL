<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'candidature_id',
        'participant_one_id',
        'participant_two_id',
        'last_message_at',
        'starred_by',
        'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'starred_by' => 'array',
            'deleted_by' => 'array',
        ];
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    public function participantOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_one_id');
    }

    public function participantTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isParticipant(string $userId): bool
    {
        return $this->participant_one_id === $userId || $this->participant_two_id === $userId;
    }

    public function isStarredBy(string $userId): bool
    {
        return in_array($userId, $this->starred_by ?? [], true);
    }

    public function isDeletedBy(string $userId): bool
    {
        return in_array($userId, $this->deleted_by ?? [], true);
    }

    public function otherParticipantId(string $userId): ?string
    {
        if ($this->participant_one_id === $userId) {
            return $this->participant_two_id;
        }

        if ($this->participant_two_id === $userId) {
            return $this->participant_one_id;
        }

        return null;
    }
}
