<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Catalogue de formations partenaires — Section Formation. CRUD simple, pas
 * de génération IA (le contenu des formations est fourni par les
 * partenaires, pas généré).
 */
class CourseService
{
    public function listActive(): Collection
    {
        return Course::where('is_active', true)->orderBy('title')->get();
    }

    public function paginate(int $page, int $limit, ?string $category = null): LengthAwarePaginator
    {
        return Course::where('is_active', true)
            ->when($category, fn ($query) => $query->where('category', $category))
            ->orderBy('title')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function find(string $id): ?Course
    {
        return Course::find($id);
    }

    /**
     * @param  array{title: string, description?: string, category?: string, provider?: string, durationHours?: int, thumbnailPath?: string}  $data
     */
    public function create(array $data): Course
    {
        return Course::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'provider' => $data['provider'] ?? null,
            'duration_hours' => $data['durationHours'] ?? null,
            'thumbnail_path' => $data['thumbnailPath'] ?? null,
        ]);
    }

    public function delete(Course $course): void
    {
        $course->update(['is_active' => false]);
    }
}
