<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleCallSessionRequest;
use App\Http\Resources\CallSessionResource;
use App\Services\CallSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Calls', description: 'GORIYA Call — visioconférence intégrée (lunion.meet)')]
class CallSessionsController extends Controller
{
    public function __construct(private readonly CallSessionService $callSessionService) {}

    #[OA\Get(
        path: '/calls',
        tags: ['Calls'],
        summary: "Sessions d'appel dont l'utilisateur authentifié est l'hôte",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des sessions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CallSession'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        return CallSessionResource::collection($this->callSessionService->listFor($request->user()));
    }

    #[OA\Post(
        path: '/calls',
        tags: ['Calls'],
        summary: "Planifie une nouvelle session d'appel (crée la room côté lunion.meet)",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ScheduleCallSessionRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Session créée', content: new OA\JsonContent(ref: '#/components/schemas/CallSession')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
            new OA\Response(response: 502, description: 'Erreur du fournisseur lunion.meet'),
        ]
    )]
    public function store(ScheduleCallSessionRequest $request)
    {
        $data = $request->validated();

        $session = $this->callSessionService->schedule(
            $request->user(),
            $data['title'],
            isset($data['scheduledAt']) ? Carbon::parse($data['scheduledAt']) : null,
        );

        return new CallSessionResource($session);
    }

    #[OA\Get(
        path: '/calls/{id}',
        tags: ['Calls'],
        summary: "Détail d'une session d'appel",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Session trouvée', content: new OA\JsonContent(ref: '#/components/schemas/CallSession')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Session introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $session = $this->callSessionService->find($id);

        if (! $session) {
            abort(404, 'Call session not found');
        }

        return new CallSessionResource($session);
    }

    #[OA\Post(
        path: '/calls/{id}/join',
        tags: ['Calls'],
        summary: "Émet un jeton de connexion pour rejoindre la session (à passer au SDK/embed lunion.meet)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Jeton émis',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'room', type: 'string'),
                    new OA\Property(property: 'identity', type: 'string'),
                    new OA\Property(property: 'expiresAt', type: 'string'),
                ])
            ),
            new OA\Response(response: 400, description: 'Session déjà terminée'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Session introuvable'),
            new OA\Response(response: 502, description: 'Erreur du fournisseur lunion.meet'),
        ]
    )]
    public function join(string $id, Request $request)
    {
        $session = $this->callSessionService->find($id);

        if (! $session) {
            abort(404, 'Call session not found');
        }

        return response()->json($this->callSessionService->issueJoinToken($session, $request->user()));
    }

    #[OA\Post(
        path: '/calls/{id}/end',
        tags: ['Calls'],
        summary: "Clôture la session (hôte uniquement) — supprime la room côté lunion.meet",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Session clôturée', content: new OA\JsonContent(ref: '#/components/schemas/CallSession')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Seul l'hôte peut clôturer la session"),
            new OA\Response(response: 404, description: 'Session introuvable'),
            new OA\Response(response: 502, description: 'Erreur du fournisseur lunion.meet'),
        ]
    )]
    public function end(string $id, Request $request)
    {
        $session = $this->callSessionService->find($id);

        if (! $session) {
            abort(404, 'Call session not found');
        }

        return new CallSessionResource($this->callSessionService->end($session, $request->user()));
    }
}
