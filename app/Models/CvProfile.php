<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Profil candidat extrait du CV et validé sur /resultat-analyse-cv — un
 * enregistrement par utilisateur (contrainte d'unicité sur user_id). Voir
 * CvProfileService pour la liste des clés effectivement persistées.
 */
class CvProfile extends Model
{
    use Auditable, HasUuid;

    protected $table = 'cv_profiles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'data',
    ];

    /**
     * `data` contient des DCP (nom, téléphone, date de naissance) —
     * indésirable dans audit_logs.
     *
     * @var list<string>
     */
    protected $auditExcludes = [
        'data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
