<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\PublicProfileTheme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Page de profil public (goriya.net/{slug}) qui agrège en lecture le
 * Portfolio et les Pitch vidéo existants de l'utilisateur — voir
 * PublicProfileService. Ne duplique aucune donnée de ces modèles.
 *
 * NOTE : le CV n'est volontairement pas intégré ici — CvAnalysis n'a pas de
 * user_id dans ce backend (même limitation que documentée dans
 * ChatService), donc aucun CV ne peut être rattaché de façon fiable à un
 * profil public pour l'instant.
 */
class PublicProfile extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'slug',
        'theme',
        'is_public',
        'seo_meta',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme' => PublicProfileTheme::class,
            'is_public' => 'boolean',
            'seo_meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
