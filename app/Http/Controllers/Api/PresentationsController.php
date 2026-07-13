<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePresentationRequest;
use App\Http\Resources\PresentationResource;
use App\Services\PptxExportService;
use App\Services\PresentationService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Presentations', description: 'Créateur de Présentations & Schémas IA')]
class PresentationsController extends Controller
{
    public function __construct(
        private readonly PresentationService $presentationService,
        private readonly PptxExportService $pptxExportService,
    ) {}

    /*
    |----------------------------------------------------------------------
    | HISTORIQUE (scopé à l'utilisateur authentifié)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/presentations',
        tags: ['Presentations'],
        summary: "Liste des présentations/schémas de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des présentations',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Presentation'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        return PresentationResource::collection($this->presentationService->listFor($request->user()));
    }

    /*
    |----------------------------------------------------------------------
    | CREATE (génère la structure IA à partir du brief)
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/presentations',
        tags: ['Presentations'],
        summary: 'Génère une présentation ou un schéma à partir d\'un brief',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreatePresentationRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Présentation créée', content: new OA\JsonContent(ref: '#/components/schemas/Presentation')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreatePresentationRequest $request)
    {
        $presentation = $this->presentationService->create($request->user(), $request->validated());

        return new PresentationResource($presentation);
    }

    /*
    |----------------------------------------------------------------------
    | DÉTAIL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/presentations/{id}',
        tags: ['Presentations'],
        summary: "Détail d'une présentation",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Présentation trouvée', content: new OA\JsonContent(ref: '#/components/schemas/Presentation')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Présentation introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        $presentation = $this->presentationService->find($id, $request->user());

        if (! $presentation) {
            abort(404, 'Presentation not found');
        }

        return new PresentationResource($presentation);
    }

    /*
    |----------------------------------------------------------------------
    | EXPORT PPTX (SLIDES uniquement — voir PptxExportService)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/presentations/{id}/export-pptx',
        tags: ['Presentations'],
        summary: 'Exporte une présentation SLIDES au format .pptx',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'URL du fichier .pptx généré', content: new OA\JsonContent(properties: [new OA\Property(property: 'url', type: 'string')])),
            new OA\Response(response: 400, description: 'Type SCHEMA non exportable en .pptx'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Présentation introuvable'),
        ]
    )]
    public function exportPptx(string $id, Request $request)
    {
        $presentation = $this->presentationService->find($id, $request->user());

        if (! $presentation) {
            abort(404, 'Presentation not found');
        }

        $url = $this->pptxExportService->export($presentation);

        return response()->json(['url' => $url]);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/presentations/{id}',
        tags: ['Presentations'],
        summary: 'Supprime une présentation',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Présentation supprimée', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Presentation deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Présentation introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $presentation = $this->presentationService->find($id, $request->user());

        if (! $presentation) {
            abort(404, 'Presentation not found');
        }

        $this->presentationService->delete($presentation);

        return response()->json(['message' => 'Presentation deleted successfully']);
    }
}
