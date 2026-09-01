<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brouillon du créateur de CV — un enregistrement par utilisateur
 * (contrainte d'unicité sur user_id). Le contenu du formulaire est stocké
 * tel quel dans `data` (JSON) : voir CvService::FIELDS pour la liste des
 * clés effectivement persistées.
 */
class Cv extends Model
{
    use Auditable, HasFactory, HasUuid;

    protected $table = 'cvs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'data',
        'step',
    ];

    /**
     * `data` contient des DCP (nom, email, téléphone, adresse) et change à
     * chaque sauvegarde automatique — inutile et indésirable dans audit_logs.
     *
     * @var list<string>
     */
    protected $auditExcludes = [
        'data',
        'step',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'step' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
