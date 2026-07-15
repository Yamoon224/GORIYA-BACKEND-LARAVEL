<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCommunityRequest;
use App\Http\Resources\CommunityResource;
use App\Services\CommunityService;
use App\Services\ConnectRecommendationService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Communities', description: 'GORIYA Connect — communautés par secteur/pays/expertise')]
class CommunitiesController extends Controller
{
    public function __construct(
        private readonly CommunityService $communityService,
        private readonly ConnectRecommendationService $recommendationService,
    ) {}

    #[OA\Get(
        path: '/communities',
        tags: ['Communities'],
        summary: 'Liste des communautés',
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['SECTOR', 'COUNTRY', 'EXPERTISE'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des communautés',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Community'))
            ),
        ]
    )]
    public function index(Request $request)
    {
        return CommunityResource::collection($this->communityService->listAll($request->query('type')));
    }

    #[OA\Post(
        path: '/communities',
        tags: ['Communities'],
        summary: 'Crée une communauté',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCommunityRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Communauté créée', content: new OA\JsonContent(ref: '#/components/schemas/Community')),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function store(CreateCommunityRequest $request)
    {
        return new CommunityResource($this->communityService->create($request->validated()));
    }

    #[OA\Get(
        path: '/communities/{id}',
        tags: ['Communities'],
        summary: "Détail d'une communauté",
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Communauté trouvée', content: new OA\JsonContent(ref: '#/components/schemas/Community')),
            new OA\Response(response: 404, description: 'Communauté introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $community = $this->communityService->find($id);
        if (! $community) {
            abort(404, 'Community not found');
        }

        return new CommunityResource($community);
    }

    #[OA\Post(
        path: '/communities/{id}/join',
        tags: ['Communities'],
        summary: 'Rejoint une communauté',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Communauté rejointe'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Communauté introuvable'),
        ]
    )]
    public function join(string $id, Request $request)
    {
        $community = $this->communityService->find($id);
        if (! $community) {
            abort(404, 'Community not found');
        }

        $this->communityService->join($community, $request->user());

        return response()->json(['message' => 'Joined']);
    }

    #[OA\Delete(
        path: '/communities/{id}/join',
        tags: ['Communities'],
        summary: 'Quitte une communauté',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Communauté quittée'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Communauté introuvable'),
        ]
    )]
    public function leave(string $id, Request $request)
    {
        $community = $this->communityService->find($id);
        if (! $community) {
            abort(404, 'Community not found');
        }

        $this->communityService->leave($community, $request->user());

        return response()->json(['message' => 'Left']);
    }

    #[OA\Get(
        path: '/me/recommendations/communities',
        tags: ['Communities'],
        summary: 'Communautés suggérées',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Suggestions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Community'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function recommendations(Request $request)
    {
        return CommunityResource::collection($this->recommendationService->suggestedCommunities($request->user()));
    }
}
