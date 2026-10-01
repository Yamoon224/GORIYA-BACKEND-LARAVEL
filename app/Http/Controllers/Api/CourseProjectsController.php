<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCourseProjectRequest;
use App\Http\Resources\CourseProjectResource;
use App\Models\CourseProject;
use App\Services\CourseProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

/**
 * « Mes projets » du module Formation, scopé à l'utilisateur authentifié.
 */
#[OA\Tag(name: 'Course Projects', description: 'Projets pratiques des apprenants')]
class CourseProjectsController extends Controller
{
    public function __construct(private readonly CourseProjectService $projects) {}

    #[OA\Get(
        path: '/me/course-projects',
        tags: ['Course Projects'],
        summary: "Projets de l'apprenant",
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Projets', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CourseProject')))]
    )]
    public function index(Request $request)
    {
        return CourseProjectResource::collection($this->projects->listFor($request->user()));
    }

    #[OA\Post(
        path: '/me/course-projects',
        tags: ['Course Projects'],
        summary: 'Soumet un projet (multipart, fichier facultatif)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Projet soumis', content: new OA\JsonContent(ref: '#/components/schemas/CourseProject')),
            new OA\Response(response: 400, description: 'Données invalides'),
        ]
    )]
    public function store(SaveCourseProjectRequest $request)
    {
        $project = $this->projects->create($request->user(), $request->validated(), $request->file('file'));

        return response()->json((new CourseProjectResource($project))->resolve(), 201);
    }

    #[OA\Patch(
        path: '/me/course-projects/{id}',
        tags: ['Course Projects'],
        summary: 'Modifie un projet (POST + _method=PATCH si fichier joint)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Projet modifié', content: new OA\JsonContent(ref: '#/components/schemas/CourseProject')),
            new OA\Response(response: 404, description: 'Projet introuvable'),
        ]
    )]
    public function update(string $id, SaveCourseProjectRequest $request)
    {
        $project = $this->projectOrFail($id, $request);
        $project = $this->projects->update($project, $request->validated(), $request->file('file'));

        return new CourseProjectResource($project);
    }

    public function destroy(string $id, Request $request)
    {
        $this->projects->delete($this->projectOrFail($id, $request));

        return response()->json(['message' => 'Projet supprimé']);
    }

    public function download(string $id, Request $request)
    {
        $project = $this->projectOrFail($id, $request);
        if (! $this->projects->hasStoredFile($project)) {
            abort(404, 'Aucun fichier joint à ce projet.');
        }

        return Storage::disk('local')->download($project->file_path, $project->file_name);
    }

    private function projectOrFail(string $id, Request $request): CourseProject
    {
        return $this->projects->findFor($request->user(), $id) ?? abort(404, 'Projet introuvable');
    }
}
