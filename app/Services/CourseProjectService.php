<?php

namespace App\Services;

use App\Enums\CourseProjectStatus;
use App\Models\CourseProject;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * « Mes projets » : réalisations pratiques des apprenants. Les fichiers
 * joints restent sur le disque privé (`local`), servis derrière JWT.
 */
class CourseProjectService
{
    private const DIRECTORY = 'course-projects';

    public function listFor(User $user): Collection
    {
        return CourseProject::where('user_id', $user->id)->with('course')->orderByDesc('created_at')->get();
    }

    public function findFor(User $user, string $id): ?CourseProject
    {
        return CourseProject::where('user_id', $user->id)->with('course')->find($id);
    }

    public function find(string $id): ?CourseProject
    {
        return CourseProject::with(['course', 'user'])->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data, ?UploadedFile $file): CourseProject
    {
        $project = new CourseProject([
            'user_id' => $user->id,
            'status' => CourseProjectStatus::SUBMITTED,
        ]);
        $this->fill($project, $data);
        if ($file) {
            $this->attachFile($project, $file);
        }
        $project->save();

        return $project->load('course');
    }

    /**
     * Modifier un projet déjà relu le renvoie en relecture.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CourseProject $project, array $data, ?UploadedFile $file): CourseProject
    {
        $this->fill($project, $data);
        if ($file) {
            $this->deleteFile($project);
            $this->attachFile($project, $file);
        }
        if ($project->isDirty()) {
            $project->status = CourseProjectStatus::SUBMITTED;
        }
        $project->save();

        return $project->load('course');
    }

    public function delete(CourseProject $project): void
    {
        $this->deleteFile($project);
        $project->delete();
    }

    public function review(CourseProject $project, ?string $feedback): CourseProject
    {
        $project->update([
            'status' => CourseProjectStatus::REVIEWED,
            'feedback' => $feedback,
            'reviewed_at' => now(),
        ]);

        return $project->load(['course', 'user']);
    }

    /**
     * @param  array{status?: ?string, search?: ?string}  $filters
     */
    public function adminPaginate(int $page, int $limit, array $filters): LengthAwarePaginator
    {
        return CourseProject::with(['course', 'user'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(trim((string) ($filters['search'] ?? '')), function ($q, $search) {
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->orderByDesc('created_at')
            ->paginate(max(1, min($limit, 100)), ['*'], 'page', max(1, $page));
    }

    public function hasStoredFile(CourseProject $project): bool
    {
        return $project->file_path && Storage::disk('local')->exists($project->file_path);
    }

    private function fill(CourseProject $project, array $data): void
    {
        $map = [
            'title' => 'title',
            'description' => 'description',
            'linkUrl' => 'link_url',
            'courseId' => 'course_id',
        ];

        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $project->{$column} = $data[$key];
            }
        }
    }

    private function attachFile(CourseProject $project, UploadedFile $file): void
    {
        $filename = Str::uuid().'.'.($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $project->file_path = Storage::disk('local')->putFileAs(self::DIRECTORY, $file, $filename);
        $project->file_name = Str::limit($file->getClientOriginalName(), 250, '');
    }

    private function deleteFile(CourseProject $project): void
    {
        if ($project->file_path) {
            Storage::disk('local')->delete($project->file_path);
            $project->file_path = null;
            $project->file_name = null;
        }
    }
}
