<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Portfolio extends Model
{
    use Auditable, HasFactory, HasUuid;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    /** Thèmes proposés par l'éditeur (standard /portfolio/creer). */
    public const THEMES = ['default', 'blue', 'purple', 'green'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'skills',
        'views',
        'downloads',
        'likes',
        'created_date',
        'user_id',
        'theme',
        'status',
        'photo_path',
        'details',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_date' => 'datetime',
            'skills' => 'array',
            // Coordonnées, niveaux de compétences, projets et liens — voir
            // CreatePortfolioRequest::editorRules() pour la forme attendue.
            'details' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
