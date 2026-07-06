<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInterviewSessionRequest;
use App\Http\Requests\UpdateInterviewSessionRequest;
use App\Http\Resources\InterviewSessionResource;
use App\Models\InterviewSession;
use App\Services\InterviewSessionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Interview Sessions', description: 'Gestion des sessions d\'entretien')]
class InterviewSessionsController extends Controller
{
    public function __construct(private readonly InterviewSessionService $interviewSessionService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/interview-sessions',
        tags: ['Interview Sessions'],
        summary: 'Crée une session d\'entretien',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateInterviewSessionRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Session créée', content: new OA\JsonContent(ref: '#/components/schemas/InterviewSession')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateInterviewSessionRequest $request)
    {
        $session = $this->interviewSessionService->create($request->validated());

        return new InterviewSessionResource($session);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/interview-sessions',
        tags: ['Interview Sessions'],
        summary: 'Liste complète des sessions d\'entretien',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des sessions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/InterviewSession'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index()
    {
        return InterviewSessionResource::collection(InterviewSession::all());
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/interview-sessions/paginate',
        tags: ['Interview Sessions'],
        summary: 'Recherche paginée avec filtres',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'candidateName', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'candidateEmail', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'position', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'duration', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'score', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'COMPLETED', 'SCHEDULED'])),
            new OA\Parameter(name: 'startTime', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InterviewSession')),
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

        $paginator = $this->interviewSessionService->paginate($page, $limit, [
            'candidateName' => $request->query('candidateName'),
            'candidateEmail' => $request->query('candidateEmail'),
            'position' => $request->query('position'),
            'duration' => $request->has('duration') ? $request->query('duration') : null,
            'score' => $request->has('score') ? $request->query('score') : null,
            'status' => $request->query('status'),
            'startTime' => $request->query('startTime'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (InterviewSession $session) => (new InterviewSessionResource($session))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/interview-sessions/{id}',
        tags: ['Interview Sessions'],
        summary: 'Détail d\'une session d\'entretien',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Session trouvée', content: new OA\JsonContent(ref: '#/components/schemas/InterviewSession')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Session introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $session = InterviewSession::find($id);

        if (! $session) {
            abort(404, 'InterviewSession not found');
        }

        return new InterviewSessionResource($session);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/interview-sessions/{id}',
        tags: ['Interview Sessions'],
        summary: 'Met à jour une session d\'entretien',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateInterviewSessionRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Session mise à jour', content: new OA\JsonContent(ref: '#/components/schemas/InterviewSession')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Session introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdateInterviewSessionRequest $request)
    {
        $session = InterviewSession::find($id);

        if (! $session) {
            abort(404, 'InterviewSession not found');
        }

        $updated = $this->interviewSessionService->update($session, $request->validated());

        return new InterviewSessionResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/interview-sessions/{id}',
        tags: ['Interview Sessions'],
        summary: 'Supprime une session d\'entretien',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Session supprimée', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'InterviewSession deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Session introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $session = InterviewSession::find($id);

        if (! $session) {
            abort(404, 'InterviewSession not found');
        }

        $this->interviewSessionService->remove($session);

        return response()->json(['message' => 'InterviewSession deleted successfully']);
    }
}
