<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Services\Admin\AdminReportingService;
use App\Services\CalendarEventService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-planning.controller.ts. La liste
 * d'événements (getEvents) est un filtre par date sur AdminReportingService,
 * pas la pagination générique de CalendarEventsController.
 */
#[OA\Tag(name: 'Admin Planning', description: 'Gestion du planning / des événements de calendrier côté admin')]
class AdminPlanningController extends Controller
{
    public function __construct(
        private readonly CalendarEventService $calendarEventService,
        private readonly AdminReportingService $adminReportingService,
    ) {}

    #[OA\Get(
        path: '/admin/planning/stats',
        tags: ['Admin Planning'],
        summary: 'Statistiques du planning (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'totalEvents', type: 'integer'),
                            new OA\Property(property: 'upcomingEvents', type: 'integer'),
                            new OA\Property(property: 'completedEvents', type: 'integer'),
                            new OA\Property(property: 'cancelledEvents', type: 'integer'),
                        ],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function stats()
    {
        return ApiResponse::success($this->adminReportingService->getPlanningStats());
    }

    #[OA\Get(
        path: '/admin/planning/events',
        tags: ['Admin Planning'],
        summary: "Liste des événements (filtrés par date si fournie, sinon tous — pas la pagination générique de /calendar-events) (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', description: 'Filtre les événements sur ce jour (bornes début/fin de journée) ; si omis, retourne tous les événements', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des événements',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarEvent')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function events(Request $request)
    {
        return ApiResponse::success(
            CalendarEventResource::collection(
                collect($this->adminReportingService->getPlanningEvents($request->query('date')))
            )
        );
    }

    #[OA\Get(
        path: '/admin/planning/upcoming',
        tags: ['Admin Planning'],
        summary: 'Liste des prochains événements confirmés/en attente, triés par date (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 5)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des prochains événements',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarEvent')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function upcoming(Request $request)
    {
        $limit = (int) $request->query('limit', 5);

        return ApiResponse::success(
            CalendarEventResource::collection(
                collect($this->adminReportingService->getUpcomingPlanningEvents($limit))
            )
        );
    }

    #[OA\Get(
        path: '/admin/planning/events/{id}',
        tags: ['Admin Planning'],
        summary: "Détail d'un événement (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Événement trouvé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/CalendarEvent'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'CalendarEvent introuvable'),
        ]
    )]
    public function showEvent(string $id)
    {
        $event = CalendarEvent::find($id);

        if (! $event) {
            abort(404, 'CalendarEvent not found');
        }

        return ApiResponse::success(new CalendarEventResource($event));
    }

    #[OA\Post(
        path: '/admin/planning/events',
        tags: ['Admin Planning'],
        summary: 'Crée un événement de calendrier (rôle ADMIN requis, aucune validation FormRequest)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'type', 'startTime', 'endTime', 'participants', 'status'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'type', type: 'string', enum: ['ENTRETIEN', 'FORMATION', 'REUNION']),
                    new OA\Property(property: 'startTime', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'endTime', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'participants', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['CONFIRMED', 'PENDING', 'CANCELLED']),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Événement créé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/CalendarEvent'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function storeEvent(Request $request)
    {
        $event = $this->calendarEventService->create($request->all());

        return ApiResponse::success(new CalendarEventResource($event));
    }

    #[OA\Patch(
        path: '/admin/planning/events/{id}',
        tags: ['Admin Planning'],
        summary: 'Met à jour un événement de calendrier (rôle ADMIN requis, aucune validation FormRequest)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'title', type: 'string', nullable: true),
                new OA\Property(property: 'type', type: 'string', enum: ['ENTRETIEN', 'FORMATION', 'REUNION'], nullable: true),
                new OA\Property(property: 'startTime', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'endTime', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'participants', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                new OA\Property(property: 'location', type: 'string', nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['CONFIRMED', 'PENDING', 'CANCELLED'], nullable: true),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Événement mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/CalendarEvent'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'CalendarEvent introuvable'),
        ]
    )]
    public function updateEvent(string $id, Request $request)
    {
        $event = CalendarEvent::find($id);

        if (! $event) {
            abort(404, 'CalendarEvent not found');
        }

        $updated = $this->calendarEventService->update($event, $request->all());

        return ApiResponse::success(new CalendarEventResource($updated));
    }

    #[OA\Delete(
        path: '/admin/planning/events/{id}',
        tags: ['Admin Planning'],
        summary: 'Supprime un événement de calendrier (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Événement supprimé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'CalendarEvent introuvable'),
        ]
    )]
    public function destroyEvent(string $id)
    {
        $event = CalendarEvent::find($id);

        if (! $event) {
            abort(404, 'CalendarEvent not found');
        }

        $this->calendarEventService->remove($event);

        return ApiResponse::success(null);
    }
}
