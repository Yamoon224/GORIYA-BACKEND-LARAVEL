<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCandidatureRequest;
use App\Http\Requests\UpdateCandidatureRequest;
use App\Http\Resources\CandidatureResource;
use App\Models\Candidature;
use App\Models\User;
use App\Services\CandidatureService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Candidatures', description: 'Gestion des candidatures')]
class CandidaturesController extends Controller
{
    use AuthorizesOwnership;

    private const RELATIONS = ['user', 'jobOffer'];

    public function __construct(private readonly CandidatureService $candidatureService) {}

    /**
     * Propriétaire = le candidat lui-même, ou l'entreprise qui recrute pour
     * l'offre visée (pour valider/refuser une candidature).
     */
    private function isCandidatureOwner(?User $actingUser, Candidature $candidature): bool
    {
        if (! $actingUser) {
            return false;
        }

        if ($actingUser->id === $candidature->user_id) {
            return true;
        }

        return $actingUser->role === UserRole::ENTERPRISE
            && $actingUser->company_id === $candidature->jobOffer?->company_id;
    }

    /*
    |----------------------------------------------------------------------
    | CREATE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/candidatures',
        tags: ['Candidatures'],
        summary: 'Crée une candidature',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCandidatureRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Candidature créée', content: new OA\JsonContent(ref: '#/components/schemas/Candidature')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateCandidatureRequest $request)
    {
        $candidature = $this->candidatureService->create($request->validated());

        return new CandidatureResource($candidature);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/candidatures',
        tags: ['Candidatures'],
        summary: 'Liste complète des candidatures',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des candidatures',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Candidature'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index()
    {
        $candidatures = Candidature::with(self::RELATIONS)->get();

        return CandidatureResource::collection($candidatures);
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/candidatures/paginate',
        tags: ['Candidatures'],
        summary: 'Recherche paginée des candidatures avec filtres',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'candidateName', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'candidateEmail', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['EN_ATTENTE', 'EN_COURS', 'APPROUVEE', 'REJETEE'])),
            new OA\Parameter(name: 'score', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'appliedDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'userId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'jobOfferId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Candidature')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->candidatureService->paginate($page, $limit, [
            'candidateName' => $request->query('candidateName'),
            'candidateEmail' => $request->query('candidateEmail'),
            'status' => $request->query('status'),
            'score' => $request->has('score') ? $request->query('score') : null,
            'appliedDate' => $request->query('appliedDate'),
            'userId' => $request->query('userId'),
            'jobOfferId' => $request->query('jobOfferId'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Candidature $candidature) => (new CandidatureResource($candidature))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/candidatures/{id}',
        tags: ['Candidatures'],
        summary: "Détail d'une candidature",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Candidature trouvée', content: new OA\JsonContent(ref: '#/components/schemas/Candidature')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $candidature = Candidature::with(self::RELATIONS)->find($id);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        return new CandidatureResource($candidature);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/candidatures/{id}',
        tags: ['Candidatures'],
        summary: 'Met à jour une candidature',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateCandidatureRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Candidature mise à jour', content: new OA\JsonContent(ref: '#/components/schemas/Candidature')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdateCandidatureRequest $request)
    {
        $candidature = Candidature::with(self::RELATIONS)->find($id);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        $this->authorizeOwnerOrAdmin($request->user(), $this->isCandidatureOwner($request->user(), $candidature));

        $updated = $this->candidatureService->update($candidature, $request->validated());

        return new CandidatureResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/candidatures/{id}',
        tags: ['Candidatures'],
        summary: 'Supprime une candidature',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Candidature supprimée', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Candidature deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $candidature = Candidature::with('jobOffer')->find($id);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        $this->authorizeOwnerOrAdmin($request->user(), $this->isCandidatureOwner($request->user(), $candidature));

        $this->candidatureService->remove($candidature);

        return response()->json(['message' => 'Candidature deleted successfully']);
    }
}
