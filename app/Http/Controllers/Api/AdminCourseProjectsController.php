<?php

namespace App\Http\Controllers\Api;

use App\Enums\CourseProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseProjectResource;
use App\Models\CourseProject;
use App\Services\CourseProjectService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Relecture des projets soumis par les apprenants (rôle ADMIN).
 */
#[OA\Tag(name: 'Admin Course Projects', description: 'Relecture des projets du module Formation')]
class AdminCourseProjectsController extends Controller
{
    public function __construct(private readonly CourseProjectService $projects) {}

    public function paginate(Request $request)
    {
        $request->validate(['status' => ['sometimes', 'nullable', Rule::enum(CourseProjectStatus::class)]]);

        $paginator = $this->projects->adminPaginate(
            (int) $request->query('page', 1),
            (int) $request->query('limit', 20),
            ['status' => $request->query('status'), 'search' => $request->query('search')],
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (CourseProject $p) => (new CourseProjectResource($p))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    public function review(string $id, Request $request)
    {
        $data = $request->validate(['feedback' => ['nullable', 'string', 'max:5000']]);
        $project = $this->projects->find($id) ?? abort(404, 'Projet introuvable');

        return ApiResponse::success((new CourseProjectResource($this->projects->review($project, $data['feedback'] ?? null)))->resolve());
    }

    public function download(string $id)
    {
        $project = $this->projects->find($id) ?? abort(404, 'Projet introuvable');
        if (! $this->projects->hasStoredFile($project)) {
            abort(404, 'Aucun fichier joint à ce projet.');
        }

        return Storage::disk('local')->download($project->file_path, $project->file_name);
    }
}
