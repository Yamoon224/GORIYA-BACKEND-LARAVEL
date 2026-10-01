<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\VideoProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseLesson extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'course_id',
        'section',
        'title',
        'description',
        'video_url',
        'video_provider',
        'duration_seconds',
        'position',
        'is_free_preview',
        'subtitles',
        'resources',
    ];

    protected function casts(): array
    {
        return [
            'video_provider' => VideoProvider::class,
            'duration_seconds' => 'integer',
            'position' => 'integer',
            'is_free_preview' => 'boolean',
            'subtitles' => 'array',
            'resources' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
