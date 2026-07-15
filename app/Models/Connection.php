<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suivi à sens unique entre deux utilisateurs (GORIYA Connect) — voir la
 * migration pour le choix "follow" plutôt que demande/acceptation mutuelle.
 */
class Connection extends Model
{
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'follower_id',
        'following_id',
    ];

    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    public function following(): BelongsTo
    {
        return $this->belongsTo(User::class, 'following_id');
    }
}
