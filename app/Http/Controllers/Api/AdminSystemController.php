<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminMessagingService;
use App\Services\Admin\AdminNotificationService;
use App\Services\Admin\AdminSearchService;
use App\Services\Admin\AdminSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-system.controller.ts. Messagerie,
 * notifications, recherche globale et paramètres système/email — chacun
 * délégué à un Service dédié (stockage Cache pour les sous-systèmes sans
 * table dédiée côté NestJS), plutôt qu'un unique AdminPlatformService monolithique.
 */
#[OA\Tag(name: 'Admin System', description: 'Messagerie, notifications, recherche globale et paramètres système/email (rôle ADMIN requis)')]
class AdminSystemController extends Controller
{
    public function __construct(
        private readonly AdminMessagingService $adminMessagingService,
        private readonly AdminNotificationService $adminNotificationService,
        private readonly AdminSettingsService $adminSettingsService,
        private readonly AdminSearchService $adminSearchService,
    ) {}

    /*
    |----------------------------------------------------------------------
    | MESSAGING
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/messages/conversations',
        tags: ['Admin System'],
        summary: 'Liste des conversations (rôle ADMIN requis)',
        description: 'Stockage Cache (admin:conversations) — pas de table dédiée.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversations',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'participantId', type: 'string'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function conversations()
    {
        return ApiResponse::success($this->adminMessagingService->getConversations());
    }

    #[OA\Get(
        path: '/admin/messages/conversations/{conversationId}/messages',
        tags: ['Admin System'],
        summary: "Messages d'une conversation (rôle ADMIN requis)",
        description: 'Stockage Cache (admin:conversation_messages:{conversationId}) — pas de table dédiée.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Messages (tableau vide si la conversation est inconnue)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'content', type: 'string'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function conversationMessages(string $conversationId)
    {
        return ApiResponse::success($this->adminMessagingService->getConversationMessages($conversationId));
    }

    #[OA\Post(
        path: '/admin/messages/conversations/{conversationId}/messages',
        tags: ['Admin System'],
        summary: 'Envoie un message dans une conversation (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'content', type: 'string'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Message créé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'content', type: 'string'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function sendConversationMessage(string $conversationId, Request $request)
    {
        return ApiResponse::success(
            $this->adminMessagingService->sendConversationMessage($conversationId, $request->input('content'))
        );
    }

    #[OA\Put(
        path: '/admin/messages/conversations/{conversationId}/read',
        tags: ['Admin System'],
        summary: 'Marque une conversation comme lue (rôle ADMIN requis)',
        description: "No-op réel de la source : aucune notion de « lu » n'existe pour les conversations — accepté sans effet.",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Accepté (no-op)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function markConversationAsRead(string $conversationId)
    {
        $this->adminMessagingService->markConversationAsRead($conversationId);

        return ApiResponse::success(null);
    }

    #[OA\Post(
        path: '/admin/messages/conversations',
        tags: ['Admin System'],
        summary: 'Crée une nouvelle conversation (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'participantId', type: 'string'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation créée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'participantId', type: 'string'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function createConversation(Request $request)
    {
        return ApiResponse::success($this->adminMessagingService->createConversation($request->input('participantId')));
    }

    /*
    |----------------------------------------------------------------------
    | NOTIFICATIONS
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/notifications',
        tags: ['Admin System'],
        summary: 'Liste des notifications admin (rôle ADMIN requis)',
        description: "Stockage Cache (admin:notifications) — une notification de bienvenue par défaut si le cache est vide.",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notifications',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'read', type: 'boolean'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function notifications()
    {
        return ApiResponse::success($this->adminNotificationService->getNotifications());
    }

    #[OA\Put(
        path: '/admin/notifications/{notificationId}/read',
        tags: ['Admin System'],
        summary: 'Marque une notification comme lue (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'notificationId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notification marquée comme lue',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function markNotificationAsRead(string $notificationId)
    {
        $this->adminNotificationService->markNotificationAsRead($notificationId);

        return ApiResponse::success(null);
    }

    #[OA\Put(
        path: '/admin/notifications/read-all',
        tags: ['Admin System'],
        summary: 'Marque toutes les notifications comme lues (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Toutes les notifications marquées comme lues',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function markAllNotificationsAsRead()
    {
        $this->adminNotificationService->markAllNotificationsAsRead();

        return ApiResponse::success(null);
    }

    #[OA\Put(
        path: '/admin/notifications/settings',
        tags: ['Admin System'],
        summary: 'Met à jour les préférences de notification (rôle ADMIN requis)',
        description: "Seules les clés applications/emplois/recommandations sont retenues (Request::only) et fusionnées aux valeurs par défaut, persistées en Cache (admin:notification_settings).",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'applications', type: 'boolean'),
                new OA\Property(property: 'emplois', type: 'boolean'),
                new OA\Property(property: 'recommandations', type: 'boolean'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Préférences mises à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function updateNotificationSettings(Request $request)
    {
        $this->adminNotificationService->updateNotificationSettings($request->only(['applications', 'emplois', 'recommandations']));

        return ApiResponse::success(null);
    }

    /*
    |----------------------------------------------------------------------
    | SEARCH — réponses déjà en forme {data,meta} (pagination en mémoire côté
    | AdminSearchService), renvoyées telles quelles.
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/search',
        tags: ['Admin System'],
        summary: 'Recherche globale combinée (candidats + offres) (rôle ADMIN requis)',
        description: "Réponse brute (pas d'enveloppe ApiResponse::success). Filtrage en mémoire (comme la source), pas au niveau SQL.",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Texte recherché', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sector', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'experience', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats combinés (candidats User + offres JobOffer)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function search(Request $request)
    {
        return response()->json($this->adminSearchService->searchAll($request->query()));
    }

    #[OA\Get(
        path: '/admin/search/candidates',
        tags: ['Admin System'],
        summary: 'Recherche paginée de candidats (rôle ADMIN requis)',
        description: "Réponse brute (pas d'enveloppe ApiResponse::success).",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Recherche sur nom/email', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location', in: 'query', description: 'Localisation de la société du candidat', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sector', in: 'query', description: 'Secteur de la société du candidat', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de candidats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function searchCandidates(Request $request)
    {
        return response()->json($this->adminSearchService->searchCandidates($request->query()));
    }

    #[OA\Get(
        path: '/admin/search/offers',
        tags: ['Admin System'],
        summary: "Recherche paginée d'offres d'emploi (rôle ADMIN requis)",
        description: "Réponse brute (pas d'enveloppe ApiResponse::success).",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', description: 'Recherche sur titre/description', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sector', in: 'query', description: 'Secteur de la société', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'experience', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Page d'offres",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function searchOffers(Request $request)
    {
        return response()->json($this->adminSearchService->searchOffers($request->query()));
    }

    #[OA\Get(
        path: '/admin/search/filters',
        tags: ['Admin System'],
        summary: 'Valeurs disponibles pour les filtres de recherche (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Valeurs distinctes disponibles',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'sectors', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'locations', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'experiences', type: 'array', items: new OA\Items(type: 'string')),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function searchFilters()
    {
        return ApiResponse::success($this->adminSearchService->getSearchFilters());
    }

    #[OA\Get(
        path: '/admin/search/export',
        tags: ['Admin System'],
        summary: 'Export CSV des résultats de recherche globale (rôle ADMIN requis)',
        description: "Réponse brute (pas d'enveloppe ApiResponse::success) — même filtres que la recherche globale, exportés en CSV.",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sector', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'experience', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fichier CSV en pièce jointe (search-results.csv)',
                content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function exportSearch(Request $request)
    {
        $csv = $this->adminSearchService->exportSearchCsv($request->query());

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="search-results.csv"')
            ->header('Content-Length', (string) strlen($csv));
    }

    /*
    |----------------------------------------------------------------------
    | SYSTEM / EMAIL SETTINGS
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/settings',
        tags: ['Admin System'],
        summary: 'Paramètres système (rôle ADMIN requis)',
        description: 'Stockage Cache (admin:system_settings), valeurs par défaut si jamais initialisé.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paramètres système',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'platformName', type: 'string', example: 'GORIYA'),
                        new OA\Property(property: 'mainUrl', type: 'string', example: 'https://goriya-admin.vercel.app'),
                        new OA\Property(property: 'supportEmail', type: 'string', format: 'email', example: 'support@goriya.app'),
                        new OA\Property(property: 'timezone', type: 'string', example: 'Africa/Abidjan'),
                        new OA\Property(property: 'description', type: 'string'),
                        new OA\Property(property: 'maintenanceMode', type: 'boolean'),
                        new OA\Property(property: 'maxUploadSize', type: 'integer', example: 10),
                        new OA\Property(property: 'allowedFileTypes', type: 'array', items: new OA\Items(type: 'string'), example: ['pdf', 'doc', 'docx']),
                        new OA\Property(property: 'smtpHost', type: 'string'),
                        new OA\Property(property: 'smtpPort', type: 'integer'),
                        new OA\Property(property: 'smtpUser', type: 'string'),
                        new OA\Property(property: 'senderName', type: 'string'),
                        new OA\Property(property: 'senderEmail', type: 'string', format: 'email'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function settings()
    {
        return ApiResponse::success($this->adminSettingsService->getSystemSettings());
    }

    #[OA\Patch(
        path: '/admin/settings',
        tags: ['Admin System'],
        summary: 'Met à jour les paramètres système (rôle ADMIN requis)',
        description: 'Fusionne l\'intégralité du body (Request::all()) avec les paramètres existants, sans validation de schéma.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'platformName', type: 'string'),
                new OA\Property(property: 'mainUrl', type: 'string'),
                new OA\Property(property: 'supportEmail', type: 'string', format: 'email'),
                new OA\Property(property: 'timezone', type: 'string'),
                new OA\Property(property: 'description', type: 'string'),
                new OA\Property(property: 'maintenanceMode', type: 'boolean'),
                new OA\Property(property: 'maxUploadSize', type: 'integer'),
                new OA\Property(property: 'allowedFileTypes', type: 'array', items: new OA\Items(type: 'string')),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paramètres fusionnés',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', description: 'Paramètres système complets après fusion'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function updateSettings(Request $request)
    {
        return ApiResponse::success($this->adminSettingsService->updateSystemSettings($request->all()));
    }

    #[OA\Get(
        path: '/admin/settings/email',
        tags: ['Admin System'],
        summary: 'Paramètres email (sous-ensemble des paramètres système) (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paramètres email',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'smtpHost', type: 'string'),
                        new OA\Property(property: 'smtpPort', type: 'integer'),
                        new OA\Property(property: 'smtpUser', type: 'string'),
                        new OA\Property(property: 'senderName', type: 'string'),
                        new OA\Property(property: 'senderEmail', type: 'string', format: 'email'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function emailSettings()
    {
        return ApiResponse::success($this->adminSettingsService->getEmailSettings());
    }

    #[OA\Patch(
        path: '/admin/settings/email',
        tags: ['Admin System'],
        summary: 'Met à jour les paramètres email (rôle ADMIN requis)',
        description: 'Fusionne l\'intégralité du body (Request::all()) dans les paramètres système partagés, sans validation de schéma.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'smtpHost', type: 'string'),
                new OA\Property(property: 'smtpPort', type: 'integer'),
                new OA\Property(property: 'smtpUser', type: 'string'),
                new OA\Property(property: 'smtpPassword', type: 'string'),
                new OA\Property(property: 'senderName', type: 'string'),
                new OA\Property(property: 'senderEmail', type: 'string', format: 'email'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paramètres email mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function updateEmailSettings(Request $request)
    {
        $this->adminSettingsService->updateEmailSettings($request->all());

        return ApiResponse::success(null);
    }

    #[OA\Post(
        path: '/admin/settings/email/test',
        tags: ['Admin System'],
        summary: 'Teste la configuration email (rôle ADMIN requis)',
        description: 'Stub côté source — renvoie toujours un succès simulé, aucun email réel n\'est envoyé.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Résultat du test simulé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Configuration email validee'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function testEmailSettings()
    {
        return ApiResponse::success($this->adminSettingsService->testEmailSettings());
    }
}
