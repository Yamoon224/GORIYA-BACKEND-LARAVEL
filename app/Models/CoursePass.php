<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Pass découverte Formation : 7 jours d'accès complet au catalogue, une
 * seule fois par utilisateur (voir CourseAccessService::activatePass).
 */
class CoursePass extends Model
{
    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->ends_at->isFuture();
    }
}
