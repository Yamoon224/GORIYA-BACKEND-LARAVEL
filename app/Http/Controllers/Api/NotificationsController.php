<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Notifications', description: "Notifications de l'utilisateur courant (candidatures, messages)")]
class NotificationsController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(private readonly NotificationService $notificationService) {}

    #[OA\Get(
        path: '/notifications',
        tags: ['Notifications'],
        summary: "Liste des notifications de l'utilisateur courant",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des notifications',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string', enum: ['MESSAGE', 'APPLICATION_STATUS', 'SYSTEM']),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body', type: 'string', nullable: true),
                    new OA\Property(property: 'isRead', type: 'boolean'),
                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                ], type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        $notifications = $this->notificationService->listFor($request->user())->map(fn (Notification $n) => [
            'id' => $n->id,
            'type' => $n->type->value,
            'title' => $n->title,
            'body' => $n->body,
            'isRead' => $n->is_read,
            'createdAt' => $n->created_at,
        ]);

        return response()->json($notifications);
    }

    #[OA\Put(
        path: '/notifications/{id}/read',
        tags: ['Notifications'],
        summary: 'Marque une notification comme lue',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Marquée comme lue'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "N'appartient pas à l'utilisateur courant"),
        ]
    )]
    public function markRead(string $id, Request $request)
    {
        $notification = Notification::findOrFail($id);
        $this->authorizeOwnerOrAdmin($request->user(), $notification->user_id === $request->user()->id);

        $this->notificationService->markAsRead($notification);

        return response()->json(['message' => 'Notification marquée comme lue']);
    }

    #[OA\Put(
        path: '/notifications/read-all',
        tags: ['Notifications'],
        summary: "Marque toutes les notifications de l'utilisateur courant comme lues",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Toutes marquées comme lues'),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function markAllRead(Request $request)
    {
        $this->notificationService->markAllAsRead($request->user());

        return response()->json(['message' => 'Notifications marquées comme lues']);
    }

    #[OA\Delete(
        path: '/notifications/{id}',
        tags: ['Notifications'],
        summary: 'Supprime une notification',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Notification supprimée'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "N'appartient pas à l'utilisateur courant"),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $notification = Notification::findOrFail($id);
        $this->authorizeOwnerOrAdmin($request->user(), $notification->user_id === $request->user()->id);

        $this->notificationService->delete($notification);

        return response()->json(['message' => 'Notification supprimée']);
    }

    #[OA\Put(
        path: '/notifications/settings',
        tags: ['Notifications'],
        summary: 'Met à jour les préférences de notification',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'applications', type: 'boolean', nullable: true),
                new OA\Property(property: 'emplois', type: 'boolean', nullable: true),
                new OA\Property(property: 'recommandations', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Préférences mises à jour'),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function updateSettings(Request $request)
    {
        $settings = $request->validate([
            'applications' => ['sometimes', 'boolean'],
            'emplois' => ['sometimes', 'boolean'],
            'recommandations' => ['sometimes', 'boolean'],
        ]);

        $this->notificationService->updateSettings($request->user(), $settings);

        return response()->json(['message' => 'Préférences mises à jour']);
    }
}
