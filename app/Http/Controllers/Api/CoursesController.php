<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCourseRequest;
use App\Http\Resources\CourseCategoryResource;
use App\Http\Resources\CourseLessonResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\EnrollmentResource;
use App\Models\CourseBookmark;
use App\Models\CourseCategory;
use App\Models\CourseLesson;
use App\Services\CourseAccessService;
use App\Services\CourseService;
use App\Services\EnrollmentService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Courses', description: 'Module Formation : catalogue public de cours par métier')]
class CoursesController extends Controller
{
    public function __construct(
        private readonly CourseService $courseService,
        private readonly CourseAccessService $access,
        private readonly EnrollmentService $enrollments,
    ) {}

    #[OA\Get(
        path: '/courses',
        tags: ['Courses'],
        summary: 'Liste des formations publiées',
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
        path: '/course-categories',
        tags: ['Courses'],
        summary: 'Catégories métiers actives (avec nombre de cours publiés)',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Catégories',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CourseCategory'))
            ),
        ]
    )]
    public function categories()
    {
        $categories = CourseCategory::where('is_active', true)
            ->withCount(['courses' => fn ($q) => $q->visible()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CourseCategoryResource::collection($categories);
    }

    #[OA\Get(
        path: '/courses/paginate',
        tags: ['Courses'],
        summary: 'Recherche paginée des formations',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 12)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'query', description: 'uuid, slug ou libellé', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'level', in: 'query', schema: new OA\Schema(type: 'string', enum: ['BEGINNER', 'INTERMEDIATE', 'ADVANCED'])),
            new OA\Parameter(name: 'language', in: 'query', description: 'Langue audio ou de sous-titres', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'isFree', in: 'query', schema: new OA\Schema(type: 'boolean')),
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
            (int) $request->query('limit', 12),
            [
                'search' => $request->query('search'),
                'category' => $request->query('category'),
                'level' => $request->query('level'),
                'language' => $request->query('language'),
                'isFree' => $request->has('isFree') ? $request->boolean('isFree') : null,
                'sort' => $request->query('sort'),
            ],
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($course) => (new CourseResource($course))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/courses/{id}',
        tags: ['Courses'],
        summary: "Fiche d'une formation (uuid ou slug) avec programme et état de l'apprenant",
        description: "Route publique. Avec un JWT, `viewer` indique l'inscription, la progression par leçon et le favori. Les leçons verrouillées n'exposent pas leur vidéo.",
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Formation trouvée'),
            new OA\Response(response: 404, description: 'Formation introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        $course = $this->courseService->findVisible($id);

        if (! $course) {
            abort(404, 'Course not found');
        }

        $user = $request->user('api');
        $fullAccess = $this->access->hasFullAccess($user);
        $progress = $user ? $this->enrollments->lessonProgressMap($user, $course) : [];
        $enrollment = $user ? $this->enrollments->findFor($user, $course) : null;

        $lessons = $course->lessons->map(fn (CourseLesson $lesson) => CourseLessonResource::forViewer(
            $lesson,
            $this->access->canWatch($user, $course, $lesson, $fullAccess),
            $progress[$lesson->id] ?? null,
        )->resolve());

        return response()->json([
            ...(new CourseResource($course))->resolve(),
            'lessons' => $lessons->values(),
            'viewer' => [
                'isAuthenticated' => $user !== null,
                'hasFullAccess' => $fullAccess || $course->is_free,
                'enrollment' => $enrollment ? (new EnrollmentResource($enrollment))->resolve() : null,
                'isBookmarked' => $user
                    ? CourseBookmark::where('user_id', $user->id)->where('course_id', $course->id)->exists()
                    : false,
            ],
        ]);
    }

    /**
     * Ancien point d'entrée admin (conservé pour compatibilité) — le
     * back-office utilise désormais /admin/courses.
     */
    public function store(CreateCourseRequest $request)
    {
        return new CourseResource($this->courseService->create([...$request->validated(), 'isPublished' => true]));
    }

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
