<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasSlug;
use App\Concerns\HasUuid;
use App\Enums\CourseLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use Auditable, HasFactory, HasSlug, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'subtitle',
        'description',
        'category',
        'category_id',
        'provider',
        'instructor_id',
        'level',
        'language',
        'subtitle_languages',
        'learning_outcomes',
        'duration_hours',
        'thumbnail_path',
        'trailer_url',
        'is_free',
        'is_published',
        'is_active',
        'lessons_count',
        'total_minutes',
        'published_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_free' => 'boolean',
            'is_published' => 'boolean',
            'duration_hours' => 'integer',
            'lessons_count' => 'integer',
            'total_minutes' => 'integer',
            'level' => CourseLevel::class,
            'subtitle_languages' => 'array',
            'learning_outcomes' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected function slugSource(): string
    {
        return 'title';
    }

    /**
     * Visible dans le catalogue apprenant : actif (non retiré) et publié.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_published', true);
    }

    public function categoryRef(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'category_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(CourseLesson::class)->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Recalcule les compteurs dénormalisés affichés sur les cartes du
     * catalogue (« 2h30 min / 16 leçons ») après toute modification des
     * leçons.
     */
    public function refreshLessonStats(): void
    {
        $seconds = (int) $this->lessons()->sum('duration_seconds');
        $count = $this->lessons()->count();

        $this->forceFill([
            'lessons_count' => $count,
            'total_minutes' => (int) ceil($seconds / 60),
            'duration_hours' => $seconds > 0 ? max(1, (int) round($seconds / 3600)) : $this->duration_hours,
        ])->saveQuietly();
    }
}
