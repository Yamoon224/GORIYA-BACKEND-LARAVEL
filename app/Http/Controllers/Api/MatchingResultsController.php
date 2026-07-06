<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMatchingResultRequest;
use App\Http\Requests\UpdateMatchingResultRequest;
use App\Http\Resources\MatchingResultResource;
use App\Models\MatchingResult;
use App\Services\MatchingResultService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Matching Results', description: 'Gestion des résultats de matching candidat/offre')]
class MatchingResultsController extends Controller
{
    public function __construct(private readonly MatchingResultService $matchingResultService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/matching-results',
        tags: ['Matching Results'],
        summary: 'Crée un résultat de matching',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateMatchingResultRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Résultat créé', content: new OA\JsonContent(ref: '#/components/schemas/MatchingResult')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateMatchingResultRequest $request)
    {
        $result = $this->matchingResultService->create($request->validated());

        return new MatchingResultResource($result);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/matching-results',
        tags: ['Matching Results'],
        summary: 'Liste complète des résultats de matching',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des résultats',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/MatchingResult'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index()
    {
        return MatchingResultResource::collection(MatchingResult::all());
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/matching-results/paginate',
        tags: ['Matching Results'],
        summary: 'Recherche paginée avec filtres',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'candidateName', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'candidateEmail', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'position', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'company', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'matchingScore', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['NOUVEAU', 'EN_COURS', 'FINALISE'])),
            new OA\Parameter(name: 'matchDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MatchingResult')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->matchingResultService->paginate($page, $limit, [
            'candidateName' => $request->query('candidateName'),
            'candidateEmail' => $request->query('candidateEmail'),
            'position' => $request->query('position'),
            'company' => $request->query('company'),
            'matchingScore' => $request->has('matchingScore') ? $request->query('matchingScore') : null,
            'status' => $request->query('status'),
            'matchDate' => $request->query('matchDate'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (MatchingResult $result) => (new MatchingResultResource($result))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/matching-results/{id}',
        tags: ['Matching Results'],
        summary: 'Détail d\'un résultat de matching',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Résultat trouvé', content: new OA\JsonContent(ref: '#/components/schemas/MatchingResult')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Résultat introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $result = MatchingResult::find($id);

        if (! $result) {
            abort(404, 'MatchingResult not found');
        }

        return new MatchingResultResource($result);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/matching-results/{id}',
        tags: ['Matching Results'],
        summary: 'Met à jour un résultat de matching',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateMatchingResultRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Résultat mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/MatchingResult')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Résultat introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdateMatchingResultRequest $request)
    {
        $result = MatchingResult::find($id);

        if (! $result) {
            abort(404, 'MatchingResult not found');
        }

        $updated = $this->matchingResultService->update($result, $request->validated());

        return new MatchingResultResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/matching-results/{id}',
        tags: ['Matching Results'],
        summary: 'Supprime un résultat de matching',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Résultat supprimé', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'MatchingResult deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Résultat introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $result = MatchingResult::find($id);

        if (! $result) {
            abort(404, 'MatchingResult not found');
        }

        $this->matchingResultService->remove($result);

        return response()->json(['message' => 'MatchingResult deleted successfully']);
    }
}
