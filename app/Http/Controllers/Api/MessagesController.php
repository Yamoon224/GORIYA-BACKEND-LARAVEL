<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Models\Conversation;
use App\Services\MessagingService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Messagerie 1:1 entre un candidat et l'entreprise qui recrute pour l'offre
 * à laquelle il a postulé (voir MessagingService pour le modèle de données).
 */
#[OA\Tag(name: 'Messages', description: 'Messagerie entre candidats et entreprises')]
class MessagesController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(private readonly MessagingService $messagingService) {}

    #[OA\Get(
        path: '/messages/conversations',
        tags: ['Messages'],
        summary: 'Liste des conversations de l\'utilisateur courant',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des conversations',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'role', type: 'string'),
                    new OA\Property(property: 'lastMessageAt', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'unreadCount', type: 'integer'),
                    new OA\Property(property: 'lastMessage', type: 'string'),
                ], type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function conversations(Request $request)
    {
        return response()->json($this->messagingService->getConversationsFor($request->user()));
    }

    #[OA\Post(
        path: '/messages/conversations',
        tags: ['Messages'],
        summary: "Démarre (ou retrouve) la conversation liée à une candidature",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['candidatureId'], properties: [
                new OA\Property(property: 'candidatureId', type: 'string', format: 'uuid'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Conversation trouvée ou créée'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Pas autorisé pour cette candidature"),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function createConversation(Request $request)
    {
        $data = $request->validate(['candidatureId' => ['required', 'uuid']]);

        return response()->json($this->messagingService->findOrCreateForCandidature($data['candidatureId'], $request->user()));
    }

    #[OA\Get(
        path: '/messages/conversations/{conversationId}/messages',
        tags: ['Messages'],
        summary: "Messages d'une conversation",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des messages',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'senderId', type: 'string', format: 'uuid'),
                ], type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Pas participant de cette conversation"),
        ]
    )]
    public function messages(string $conversationId, Request $request)
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->authorizeOwnerOrAdmin($request->user(), $this->messagingService->isParticipant($conversation, $request->user()));

        return response()->json($this->messagingService->getMessagesFor($conversation));
    }

    #[OA\Post(
        path: '/messages/conversations/{conversationId}/messages',
        tags: ['Messages'],
        summary: 'Envoie un message dans une conversation',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SendMessageRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Message envoyé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Pas participant de cette conversation"),
        ]
    )]
    public function store(string $conversationId, SendMessageRequest $request)
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->authorizeOwnerOrAdmin($request->user(), $this->messagingService->isParticipant($conversation, $request->user()));

        return response()->json($this->messagingService->sendMessage(
            $conversation,
            $request->user(),
            $request->validated()['content'] ?? '',
            $request->file('attachment'),
        ));
    }

    #[OA\Put(
        path: '/messages/conversations/{conversationId}/star',
        tags: ['Messages'],
        summary: 'Epingle ou desepingle une conversation pour l\'utilisateur courant',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'starred', type: 'boolean', description: 'Absent = bascule'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Conversation mise a jour'),
            new OA\Response(response: 401, description: 'Non authentifie'),
            new OA\Response(response: 403, description: 'Pas participant de cette conversation'),
        ]
    )]
    public function star(string $conversationId, Request $request)
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->authorizeOwnerOrAdmin($request->user(), $this->messagingService->isParticipant($conversation, $request->user()));

        $data = $request->validate(['starred' => ['sometimes', 'boolean']]);
        // Sans `starred` explicite, l'appel bascule l'etat courant : c'est ce
        // que fait l'etoile de la barre de conversation.
        $starred = array_key_exists('starred', $data)
            ? (bool) $data['starred']
            : ! $conversation->isStarredBy($request->user()->id);

        return response()->json($this->messagingService->setStarred($conversation, $request->user(), $starred));
    }

    #[OA\Put(
        path: '/messages/conversations/{conversationId}/unread',
        tags: ['Messages'],
        summary: 'Remet une conversation en non lu pour l\'utilisateur courant',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Marquee comme non lue'),
            new OA\Response(response: 401, description: 'Non authentifie'),
            new OA\Response(response: 403, description: 'Pas participant de cette conversation'),
        ]
    )]
    public function markUnread(string $conversationId, Request $request)
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->authorizeOwnerOrAdmin($request->user(), $this->messagingService->isParticipant($conversation, $request->user()));

        $this->messagingService->markAsUnread($conversation, $request->user());

        return response()->json(['message' => 'Conversation marquee comme non lue']);
    }

    #[OA\Delete(
        path: '/messages/conversations/{conversationId}',
        tags: ['Messages'],
        summary: 'Retire la conversation de la liste de l\'utilisateur courant',
        description: "L'autre participant conserve la conversation et son historique ; un nouveau message la fait reapparaitre.",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Conversation supprimee'),
            new OA\Response(response: 401, description: 'Non authentifie'),
            new OA\Response(response: 403, description: 'Pas participant de cette conversation'),
        ]
    )]
    public function destroy(string $conversationId, Request $request)
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->authorizeOwnerOrAdmin($request->user(), $this->messagingService->isParticipant($conversation, $request->user()));

        $this->messagingService->deleteForUser($conversation, $request->user());

        return response()->json(['message' => 'Conversation supprimee']);
    }

    #[OA\Put(
        path: '/messages/conversations/{conversationId}/read',
        tags: ['Messages'],
        summary: "Marque les messages reçus d'une conversation comme lus",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Marqué comme lu'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Pas participant de cette conversation"),
        ]
    )]
    public function markRead(string $conversationId, Request $request)
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->authorizeOwnerOrAdmin($request->user(), $this->messagingService->isParticipant($conversation, $request->user()));

        $this->messagingService->markAsRead($conversation, $request->user());

        return response()->json(['message' => 'Conversation marquée comme lue']);
    }
}
