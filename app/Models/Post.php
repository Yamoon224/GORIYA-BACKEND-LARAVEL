<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'community_id',
        'repost_of_id',
        'content',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PostAttachment::class)->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    /** Post d'origine quand celui-ci est une republication. */
    public function repostOf(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'repost_of_id');
    }

    public function reposts(): HasMany
    {
        return $this->hasMany(Post::class, 'repost_of_id');
    }
}
