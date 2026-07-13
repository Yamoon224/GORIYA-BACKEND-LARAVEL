<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateResearchQueryRequest;
use App\Http\Resources\ResearchQueryResource;
use App\Services\CompanyResearchService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Research', description: "Goriya IA Research — recherche IA sur une entreprise avant un entretien")]
class CompanyResearchController extends Controller
{
    public function __construct(private readonly CompanyResearchService $researchService) {}

    /*
    |----------------------------------------------------------------------
    | HISTORIQUE (scopé à l'utilisateur authentifié)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/research',
        tags: ['Research'],
        summary: "Historique des recherches de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Historique des recherches',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ResearchQuery'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        return ResearchQueryResource::collection($this->researchService->listFor($request->user()));
    }

    /*
    |----------------------------------------------------------------------
    | NOUVELLE RECHERCHE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/research',
        tags: ['Research'],
        summary: "Lance une recherche IA sur une entreprise",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateResearchQueryRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Recherche créée', content: new OA\JsonContent(ref: '#/components/schemas/ResearchQuery')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateResearchQueryRequest $request)
    {
        $query = $this->researchService->research($request->user(), $request->validated()['companyName']);

        return new ResearchQueryResource($query);
    }

    /*
    |----------------------------------------------------------------------
    | DÉTAIL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/research/{id}',
        tags: ['Research'],
        summary: "Détail d'une recherche",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Recherche trouvée', content: new OA\JsonContent(ref: '#/components/schemas/ResearchQuery')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Recherche introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        $query = $this->researchService->find($id, $request->user());

        if (! $query) {
            abort(404, 'ResearchQuery not found');
        }

        return new ResearchQueryResource($query);
    }

    /*
    |----------------------------------------------------------------------
    | FAVORI
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/research/{id}/favorite',
        tags: ['Research'],
        summary: "Bascule le statut favori d'une recherche",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Statut favori mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/ResearchQuery')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Recherche introuvable'),
        ]
    )]
    public function toggleFavorite(string $id, Request $request)
    {
        $query = $this->researchService->find($id, $request->user());

        if (! $query) {
            abort(404, 'ResearchQuery not found');
        }

        return new ResearchQueryResource($this->researchService->toggleFavorite($query));
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/research/{id}',
        tags: ['Research'],
        summary: 'Supprime une recherche',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Recherche supprimée', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'ResearchQuery deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Recherche introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $query = $this->researchService->find($id, $request->user());

        if (! $query) {
            abort(404, 'ResearchQuery not found');
        }

        $this->researchService->delete($query);

        return response()->json(['message' => 'ResearchQuery deleted successfully']);
    }
}
