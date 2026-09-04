<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResumeResource;
use App\Services\UserResumeService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Bibliothèque de CV de l'utilisateur authentifié. Alimente l'étape « CV » du
 * wizard de candidature (standard) : lister, téléverser, renommer / marquer
 * par défaut, supprimer.
 */
#[OA\Tag(name: 'CV du candidat', description: 'CV téléversés par le candidat')]
class MyResumesController extends Controller
{
    public function __construct(private readonly UserResumeService $resumeService) {}

    #[OA\Get(
        path: '/me/resumes',
        tags: ['CV du candidat'],
        summary: "Liste des CV de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des CV',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/UserResume')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        return ApiResponse::success(
            UserResumeResource::collection($this->resumeService->listForUser($request->user()))->resolve()
        );
    }

    #[OA\Post(
        path: '/me/resumes',
        tags: ['CV du candidat'],
        summary: 'Téléverse un CV (PDF ou Word, 5 Mo maximum)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                ])
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'CV enregistré'),
            new OA\Response(response: 400, description: 'Format ou taille refusés'),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $resume = $this->resumeService->store(
            $request->user(),
            $request->file('file'),
            $request->input('name'),
        );

        return ApiResponse::success((new UserResumeResource($resume))->resolve());
    }

    #[OA\Patch(
        path: '/me/resumes/{id}',
        tags: ['CV du candidat'],
        summary: 'Renomme un CV ou le définit comme CV par défaut',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'CV mis à jour'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'CV introuvable'),
        ]
    )]
    public function update(string $id, Request $request)
    {
        $resume = $this->resumeService->findForUser($request->user(), $id);
        if (! $resume) {
            abort(404, 'CV introuvable');
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'isDefault' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::success((new UserResumeResource($this->resumeService->update($resume, $data)))->resolve());
    }

    #[OA\Delete(
        path: '/me/resumes/{id}',
        tags: ['CV du candidat'],
        summary: 'Supprime un CV de la bibliothèque',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'CV supprimé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'CV introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $resume = $this->resumeService->findForUser($request->user(), $id);
        if (! $resume) {
            abort(404, 'CV introuvable');
        }

        $this->resumeService->delete($resume);

        return ApiResponse::success(null);
    }
}
