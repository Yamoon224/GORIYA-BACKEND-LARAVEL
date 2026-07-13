<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\PresentationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Structure générée par IA pour un jeu de slides ou un schéma (organigramme,
 * mind map, diagramme de flux, roadmap). `content` porte la structure
 * logique (titres/puces pour SLIDES, nœuds/arêtes pour SCHEMA) — le rendu
 * visuel PDF/PNG/SVG se fait côté frontend ; seul l'export .pptx (SLIDES
 * uniquement) est rendu côté serveur, voir PptxExportService.
 */
class Presentation extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'type',
        'brief',
        'content',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PresentationType::class,
            'content' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
