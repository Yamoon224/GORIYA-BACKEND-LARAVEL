<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostComment extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'user_id',
        'content',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
