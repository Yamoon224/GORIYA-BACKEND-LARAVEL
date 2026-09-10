<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce jointe d'un post GORIYA Connect : une image (jusqu'à 9 par post) ou un
 * document PDF (un seul, affiché seul) — voir PostService::storeAttachments().
 */
class PostAttachment extends Model
{
    use HasUuid;

    public const TYPE_IMAGE = 'IMAGE';

    public const TYPE_DOCUMENT = 'DOCUMENT';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'type',
        'path',
        'name',
        'mime_type',
        'size',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'position' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
