<?php

namespace App\Services;

use App\Enums\VideoProvider;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseLesson;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Catalogue du module Formation : cours, leçons et recherche. Le contenu
 * (vidéos) est hébergé à l'extérieur ; on ne stocke que les métadonnées et
 * les vignettes.
 */
class CourseService
{
    private const RELATIONS = ['categoryRef', 'instructor'];

    public function listActive(): Collection
    {
        return Course::visible()->with(self::RELATIONS)->orderBy('title')->get();
    }

    /**
     * @param  array{search?: ?string, category?: ?string, level?: ?string, language?: ?string, isFree?: ?bool, sort?: ?string}  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $query = Course::visible()->with(self::RELATIONS);
        $this->applyFilters($query, $filters);

        match ($filters['sort'] ?? null) {
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('published_at')->orderByDesc('created_at'),
        };

        return $query->paginate(max(1, min($limit, 50)), ['*'], 'page', max(1, $page));
    }

    /**
     * @param  array{search?: ?string, category?: ?string, level?: ?string, isFree?: ?bool, status?: ?string}  $filters
     */
    public function adminPaginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $query = Course::query()->with(self::RELATIONS);
        $this->applyFilters($query, $filters);

        match ($filters['status'] ?? null) {
            'PUBLISHED' => $query->where('is_active', true)->where('is_published', true),
            'DRAFT' => $query->where('is_active', true)->where('is_published', false),
            'ARCHIVED' => $query->where('is_active', false),
            default => null,
        };

        return $query->orderByDesc('updated_at')->paginate(max(1, min($limit, 100)), ['*'], 'page', max(1, $page));
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('subtitle', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhereHas('categoryRef', fn (Builder $c) => $c->where('name', 'like', $like))
                    ->orWhereHas('instructor', fn (Builder $i) => $i->where('name', 'like', $like));
            });
        }

        // Accepte l'uuid ou le slug de la catégorie, ou l'ancien libellé
        // texte (appels antérieurs au module structuré).
        if ($category = $filters['category'] ?? null) {
            $query->where(function (Builder $q) use ($category) {
                $q->whereHas('categoryRef', fn (Builder $c) => $c->whereKeyOrSlug($category))
                    ->orWhere('category', $category);
            });
        }

        if ($level = $filters['level'] ?? null) {
            $query->where('level', $level);
        }

        if ($language = $filters['language'] ?? null) {
            $query->where(function (Builder $q) use ($language) {
                $q->where('language', $language)->orWhereJsonContains('subtitle_languages', $language);
            });
        }

        if (($filters['isFree'] ?? null) !== null) {
            $query->where('is_free', (bool) $filters['isFree']);
        }
    }

    public function find(string $idOrSlug): ?Course
    {
        return Course::whereKeyOrSlug($idOrSlug)->with(self::RELATIONS)->first();
    }

    public function findVisible(string $idOrSlug): ?Course
    {
        return Course::visible()->whereKeyOrSlug($idOrSlug)->with([...self::RELATIONS, 'lessons'])->first();
    }

    /**
     * @param  array<string, mixed>  $data  payload camelCase validé
     */
    public function create(array $data): Course
    {
        $course = new Course(['is_active' => true]);
        $this->fill($course, $data);
        $course->save();

        return $course->load(self::RELATIONS);
    }

    /**
     * @param  array<string, mixed>  $data  payload camelCase validé (partiel)
     */
    public function update(Course $course, array $data): Course
    {
        $this->fill($course, $data);
        $course->save();

        return $course->load(self::RELATIONS);
    }

    private function fill(Course $course, array $data): void
    {
        $map = [
            'title' => 'title',
            'subtitle' => 'subtitle',
            'description' => 'description',
            'provider' => 'provider',
            'instructorId' => 'instructor_id',
            'level' => 'level',
            'language' => 'language',
            'subtitleLanguages' => 'subtitle_languages',
            'learningOutcomes' => 'learning_outcomes',
            'durationHours' => 'duration_hours',
            'thumbnailPath' => 'thumbnail_path',
            'trailerUrl' => 'trailer_url',
            'isFree' => 'is_free',
            'isActive' => 'is_active',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $course->{$column} = $data[$key];
            }
        }

        // category_id est la référence ; la colonne texte `category` est
        // gardée synchronisée pour les écrans qui la lisent encore.
        if (array_key_exists('categoryId', $data)) {
            $course->category_id = $data['categoryId'];
            $course->category = $data['categoryId'] ? CourseCategory::find($data['categoryId'])?->name : null;
        } elseif (array_key_exists('category', $data)) {
            $course->category = $data['category'];
        }

        if (array_key_exists('isPublished', $data)) {
            $course->is_published = (bool) $data['isPublished'];
            if ($course->is_published && ! $course->published_at) {
                $course->published_at = now();
            }
        }
    }

    public function delete(Course $course): void
    {
        $course->update(['is_active' => false]);
    }

    public function storeImage(UploadedFile $file): string
    {
        $filename = Str::uuid().'.'.($file->guessExtension() ?: 'jpg');

        return '/storage/'.Storage::disk('public')->putFileAs('courses', $file, $filename);
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'courses' => Course::where('is_active', true)->count(),
            'published' => Course::visible()->count(),
            'lessons' => CourseLesson::count(),
            'enrollments' => DB::table('enrollments')->count(),
            'completions' => DB::table('enrollments')->where('status', 'COMPLETED')->count(),
            'pendingProjects' => DB::table('course_projects')->where('status', 'SUBMITTED')->count(),
            'activePasses' => DB::table('course_passes')->where('ends_at', '>', now())->count(),
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Leçons
    |----------------------------------------------------------------------
    */

    public function findLesson(string $id): ?CourseLesson
    {
        return CourseLesson::with('course')->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createLesson(Course $course, array $data): CourseLesson
    {
        $lesson = new CourseLesson([
            'course_id' => $course->id,
            'position' => (int) $course->lessons()->max('position') + 1,
        ]);
        $this->fillLesson($lesson, $data);
        $lesson->save();
        $course->refreshLessonStats();

        return $lesson;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLesson(CourseLesson $lesson, array $data): CourseLesson
    {
        $this->fillLesson($lesson, $data);
        $lesson->save();
        $lesson->course->refreshLessonStats();

        return $lesson;
    }

    public function deleteLesson(CourseLesson $lesson): void
    {
        $course = $lesson->course;
        $lesson->delete();
        $course->refreshLessonStats();
    }

    /**
     * @param  list<string>  $orderedIds
     */
    public function reorderLessons(Course $course, array $orderedIds): Collection
    {
        DB::transaction(function () use ($course, $orderedIds) {
            foreach (array_values($orderedIds) as $index => $id) {
                CourseLesson::where('course_id', $course->id)->whereKey($id)->update(['position' => $index + 1]);
            }
        });

        return $course->lessons()->get();
    }

    private function fillLesson(CourseLesson $lesson, array $data): void
    {
        $map = [
            'section' => 'section',
            'title' => 'title',
            'description' => 'description',
            'durationSeconds' => 'duration_seconds',
            'isFreePreview' => 'is_free_preview',
            'subtitles' => 'subtitles',
            'resources' => 'resources',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $lesson->{$column} = $data[$key];
            }
        }

        if (array_key_exists('videoUrl', $data)) {
            $lesson->video_url = $data['videoUrl'];
            $lesson->video_provider = $data['videoProvider'] ?? VideoProvider::detect($data['videoUrl']);
        } elseif (array_key_exists('videoProvider', $data)) {
            $lesson->video_provider = $data['videoProvider'];
        }
    }
}
