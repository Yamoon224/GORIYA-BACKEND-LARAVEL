<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCalendarEventRequest;
use App\Http\Requests\UpdateCalendarEventRequest;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Services\CalendarEventService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Calendar Events', description: 'Gestion des événements de calendrier (entretiens, formations, réunions)')]
class CalendarEventsController extends Controller
{
    public function __construct(private readonly CalendarEventService $calendarEventService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/calendar-events',
        tags: ['Calendar Events'],
        summary: 'Crée un événement de calendrier',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCalendarEventRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Événement créé', content: new OA\JsonContent(ref: '#/components/schemas/CalendarEvent')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateCalendarEventRequest $request)
    {
        $event = $this->calendarEventService->create($request->validated());

        return new CalendarEventResource($event);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/calendar-events',
        tags: ['Calendar Events'],
        summary: 'Liste complète des événements de calendrier',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des événements',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarEvent'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index()
    {
        return CalendarEventResource::collection(CalendarEvent::all());
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/calendar-events/paginate',
        tags: ['Calendar Events'],
        summary: 'Recherche paginée avec filtres',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'title', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ENTRETIEN', 'FORMATION', 'REUNION'])),
            new OA\Parameter(name: 'startTime', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'endTime', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'participants', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['CONFIRMED', 'PENDING', 'CANCELLED'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarEvent')),
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

        $paginator = $this->calendarEventService->paginate($page, $limit, [
            'title' => $request->query('title'),
            'type' => $request->query('type'),
            'startTime' => $request->query('startTime'),
            'endTime' => $request->query('endTime'),
            'participants' => $request->query('participants'),
            'location' => $request->query('location'),
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (CalendarEvent $event) => (new CalendarEventResource($event))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/calendar-events/{id}',
        tags: ['Calendar Events'],
        summary: 'Détail d\'un événement de calendrier',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Événement trouvé', content: new OA\JsonContent(ref: '#/components/schemas/CalendarEvent')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Événement introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $event = CalendarEvent::find($id);

        if (! $event) {
            abort(404, 'CalendarEvent not found');
        }

        return new CalendarEventResource($event);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/calendar-events/{id}',
        tags: ['Calendar Events'],
        summary: 'Met à jour un événement de calendrier',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateCalendarEventRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Événement mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/CalendarEvent')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Événement introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdateCalendarEventRequest $request)
    {
        $event = CalendarEvent::find($id);

        if (! $event) {
            abort(404, 'CalendarEvent not found');
        }

        $updated = $this->calendarEventService->update($event, $request->validated());

        return new CalendarEventResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/calendar-events/{id}',
        tags: ['Calendar Events'],
        summary: 'Supprime un événement de calendrier',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Événement supprimé', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'CalendarEvent deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Événement introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $event = CalendarEvent::find($id);

        if (! $event) {
            abort(404, 'CalendarEvent not found');
        }

        $this->calendarEventService->remove($event);

        return response()->json(['message' => 'CalendarEvent deleted successfully']);
    }
}
