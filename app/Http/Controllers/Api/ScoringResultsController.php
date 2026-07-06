<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateScoringResultRequest;
use App\Http\Requests\UpdateScoringResultRequest;
use App\Http\Resources\ScoringResultResource;
use App\Models\ScoringResult;
use App\Services\ScoringResultService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Scoring Results', description: 'Gestion des résultats de scoring des candidatures')]
class ScoringResultsController extends Controller
{
    public function __construct(private readonly ScoringResultService $scoringResultService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/scoring-results',
        tags: ['Scoring Results'],
        summary: 'Crée un résultat de scoring',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateScoringResultRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Résultat créé', content: new OA\JsonContent(ref: '#/components/schemas/ScoringResult')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateScoringResultRequest $request)
    {
        $result = $this->scoringResultService->create($request->validated());

        return new ScoringResultResource($result);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/scoring-results',
        tags: ['Scoring Results'],
        summary: 'Liste complète des résultats de scoring',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des résultats',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ScoringResult'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index()
    {
        return ScoringResultResource::collection(ScoringResult::all());
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/scoring-results/paginate',
        tags: ['Scoring Results'],
        summary: 'Recherche paginée avec filtres',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'candidateName', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'candidateEmail', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'position', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'overallScore', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'analysisDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['COMPLETED', 'IN_PROGRESS'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ScoringResult')),
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

        $paginator = $this->scoringResultService->paginate($page, $limit, [
            'candidateName' => $request->query('candidateName'),
            'candidateEmail' => $request->query('candidateEmail'),
            'position' => $request->query('position'),
            'overallScore' => $request->has('overallScore') ? $request->query('overallScore') : null,
            'analysisDate' => $request->query('analysisDate'),
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (ScoringResult $result) => (new ScoringResultResource($result))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/scoring-results/{id}',
        tags: ['Scoring Results'],
        summary: 'Détail d\'un résultat de scoring',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Résultat trouvé', content: new OA\JsonContent(ref: '#/components/schemas/ScoringResult')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Résultat introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $result = ScoringResult::find($id);

        if (! $result) {
            abort(404, 'ScoringResult not found');
        }

        return new ScoringResultResource($result);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/scoring-results/{id}',
        tags: ['Scoring Results'],
        summary: 'Met à jour un résultat de scoring',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateScoringResultRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Résultat mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/ScoringResult')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Résultat introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdateScoringResultRequest $request)
    {
        $result = ScoringResult::find($id);

        if (! $result) {
            abort(404, 'ScoringResult not found');
        }

        $updated = $this->scoringResultService->update($result, $request->validated());

        return new ScoringResultResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/scoring-results/{id}',
        tags: ['Scoring Results'],
        summary: 'Supprime un résultat de scoring',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Résultat supprimé', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'ScoringResult deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Résultat introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $result = ScoringResult::find($id);

        if (! $result) {
            abort(404, 'ScoringResult not found');
        }

        $this->scoringResultService->remove($result);

        return response()->json(['message' => 'ScoringResult deleted successfully']);
    }
}
