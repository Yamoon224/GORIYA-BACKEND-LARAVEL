<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    use HasUuid;

    protected $table = 'lesson_progress';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'lesson_id',
        'course_id',
        'last_position_seconds',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_position_seconds' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class, 'lesson_id');
    }
}
