<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCourseRequest;
use App\Http\Resources\CourseResource;
use App\Services\CourseService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Courses', description: 'Catalogue de formations partenaires (Section Formation)')]
class CoursesController extends Controller
{
    public function __construct(private readonly CourseService $courseService) {}

    #[OA\Get(
        path: '/courses',
        tags: ['Courses'],
        summary: 'Liste des formations actives',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des formations',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Course'))
            ),
        ]
    )]
    public function index()
    {
        return CourseResource::collection($this->courseService->listActive());
    }

    #[OA\Get(
        path: '/courses/paginate',
        tags: ['Courses'],
        summary: 'Recherche paginée des formations',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Course')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
        ]
    )]
    public function paginate(Request $request)
    {
        $paginator = $this->courseService->paginate(
            (int) $request->query('page', 1),
            (int) $request->query('limit', 10),
            $request->query('category'),
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($course) => (new CourseResource($course))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/courses/{id}',
        tags: ['Courses'],
        summary: "Détail d'une formation",
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Formation trouvée', content: new OA\JsonContent(ref: '#/components/schemas/Course')),
            new OA\Response(response: 404, description: 'Formation introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $course = $this->courseService->find($id);

        if (! $course) {
            abort(404, 'Course not found');
        }

        return new CourseResource($course);
    }

    #[OA\Post(
        path: '/courses',
        tags: ['Courses'],
        summary: 'Ajoute une formation au catalogue (admin)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCourseRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Formation créée', content: new OA\JsonContent(ref: '#/components/schemas/Course')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Réservé aux administrateurs'),
        ]
    )]
    public function store(CreateCourseRequest $request)
    {
        return new CourseResource($this->courseService->create($request->validated()));
    }

    #[OA\Delete(
        path: '/courses/{id}',
        tags: ['Courses'],
        summary: 'Retire une formation du catalogue actif (admin)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Formation retirée'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Réservé aux administrateurs'),
            new OA\Response(response: 404, description: 'Formation introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $course = $this->courseService->find($id);

        if (! $course) {
            abort(404, 'Course not found');
        }

        $this->courseService->delete($course);

        return response()->json(['message' => 'Course removed from active catalog']);
    }
}
