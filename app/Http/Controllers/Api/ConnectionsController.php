<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectUserResource;
use App\Http\Resources\JobOfferResource;
use App\Models\User;
use App\Services\ConnectionService;
use App\Services\ConnectRecommendationService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Connections', description: 'GORIYA Connect — mise en relation entre utilisateurs')]
class ConnectionsController extends Controller
{
    public function __construct(
        private readonly ConnectionService $connectionService,
        private readonly ConnectRecommendationService $recommendationService,
    ) {}

    #[OA\Post(
        path: '/users/{userId}/follow',
        tags: ['Connections'],
        summary: 'Suit un utilisateur',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Suivi'),
            new OA\Response(response: 400, description: 'Impossible de se suivre soi-même'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function follow(string $userId, Request $request)
    {
        $target = User::find($userId);
        if (! $target) {
            abort(404, 'User not found');
        }

        $this->connectionService->follow($request->user(), $target);

        return response()->json(['message' => 'Followed']);
    }

    #[OA\Delete(
        path: '/users/{userId}/follow',
        tags: ['Connections'],
        summary: 'Ne plus suivre un utilisateur',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Ne suit plus'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function unfollow(string $userId, Request $request)
    {
        $target = User::find($userId);
        if (! $target) {
            abort(404, 'User not found');
        }

        $this->connectionService->unfollow($request->user(), $target);

        return response()->json(['message' => 'Unfollowed']);
    }

    #[OA\Get(
        path: '/me/followers',
        tags: ['Connections'],
        summary: 'Liste des personnes qui me suivent',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des followers',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ConnectUser'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function followers(Request $request)
    {
        return ConnectUserResource::collection($this->connectionService->followers($request->user()));
    }

    #[OA\Get(
        path: '/me/following',
        tags: ['Connections'],
        summary: 'Liste des personnes que je suis',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des suivis',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ConnectUser'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function following(Request $request)
    {
        return ConnectUserResource::collection($this->connectionService->following($request->user()));
    }

    #[OA\Get(
        path: '/me/recommendations/people',
        tags: ['Connections'],
        summary: 'Personnes à suivre suggérées',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Suggestions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ConnectUser'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function peopleRecommendations(Request $request)
    {
        return ConnectUserResource::collection($this->recommendationService->peopleToFollow($request->user()));
    }

    #[OA\Get(
        path: '/me/recommendations/job-offers',
        tags: ['Connections'],
        summary: 'Offres à ne pas manquer',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Suggestions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function jobOfferRecommendations()
    {
        return JobOfferResource::collection($this->recommendationService->jobOffersToWatch());
    }
}
