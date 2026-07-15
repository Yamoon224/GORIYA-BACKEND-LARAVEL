<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateChatThreadRequest;
use App\Http\Requests\SendChatMessageRequest;
use App\Http\Resources\ChatThreadResource;
use App\Services\ChatService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Chat', description: 'GORIYA Chat — assistant IA conversationnel')]
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chatService) {}

    /*
    |----------------------------------------------------------------------
    | LISTE DES FILS (scopé à l'utilisateur authentifié)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/chat/threads',
        tags: ['Chat'],
        summary: "Liste des fils de conversation de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des fils',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ChatThread'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        return ChatThreadResource::collection($this->chatService->listThreadsFor($request->user()));
    }

    /*
    |----------------------------------------------------------------------
    | NOUVEAU FIL (premier message)
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/chat/threads',
        tags: ['Chat'],
        summary: 'Crée un fil de conversation et envoie le premier message',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateChatThreadRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Fil créé avec la réponse IA', content: new OA\JsonContent(ref: '#/components/schemas/ChatThread')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateChatThreadRequest $request)
    {
        $user = $request->user();
        $thread = $this->chatService->createThread($user);
        $thread = $this->chatService->sendMessage($thread, $user, $request->validated()['message']);

        return new ChatThreadResource($thread);
    }

    /*
    |----------------------------------------------------------------------
    | DÉTAIL (fil + messages)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/chat/threads/{id}',
        tags: ['Chat'],
        summary: "Détail d'un fil avec ses messages",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fil trouvé', content: new OA\JsonContent(ref: '#/components/schemas/ChatThread')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Fil introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        $thread = $this->chatService->findThread($id, $request->user());

        if (! $thread) {
            abort(404, 'ChatThread not found');
        }

        return new ChatThreadResource($thread);
    }

    /*
    |----------------------------------------------------------------------
    | ENVOI D'UN MESSAGE DANS UN FIL EXISTANT
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/chat/threads/{id}/messages',
        tags: ['Chat'],
        summary: 'Envoie un message dans un fil existant et retourne la réponse IA',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SendChatMessageRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Fil mis à jour avec la réponse IA', content: new OA\JsonContent(ref: '#/components/schemas/ChatThread')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Fil introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function sendMessage(string $id, SendChatMessageRequest $request)
    {
        $thread = $this->chatService->findThread($id, $request->user());

        if (! $thread) {
            abort(404, 'ChatThread not found');
        }

        $thread = $this->chatService->sendMessage($thread, $request->user(), $request->validated()['message']);

        return new ChatThreadResource($thread);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/chat/threads/{id}',
        tags: ['Chat'],
        summary: 'Supprime un fil de conversation',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fil supprimé', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'ChatThread deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Fil introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $thread = $this->chatService->findThread($id, $request->user());

        if (! $thread) {
            abort(404, 'ChatThread not found');
        }

        $this->chatService->deleteThread($thread);

        return response()->json(['message' => 'ChatThread deleted successfully']);
    }
}
