<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\PitchFormat;
use App\Enums\PitchStatus;
use App\Enums\PitchType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pitch Goriya — remplace ou complète la lettre de motivation traditionnelle.
 * `content` porte le script généré par IA (lu tel quel pour un pitch texte,
 * ou servant de prompteur/base de scoring pour un pitch vidéo — voir
 * PitchService::attachVideo() et ProcessPitchVideoJob).
 */
class Pitch extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'job_offer_id',
        'type',
        'format',
        'content',
        'video_path',
        'score',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PitchType::class,
            'format' => PitchFormat::class,
            'status' => PitchStatus::class,
            'score' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobOffer(): BelongsTo
    {
        return $this->belongsTo(JobOffer::class, 'job_offer_id');
    }
}
