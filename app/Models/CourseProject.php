<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\CourseProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Projet pratique soumis par un apprenant (« Mes projets »), éventuellement
 * rattaché au cours qui l'a inspiré, relu par l'équipe Goriya.
 */
class CourseProject extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'course_id',
        'title',
        'description',
        'link_url',
        'file_path',
        'file_name',
        'status',
        'feedback',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CourseProjectStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
