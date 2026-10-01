<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCourseLessonRequest;
use App\Http\Requests\SaveCourseRequest;
use App\Http\Resources\CourseLessonResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Services\CourseService;
use App\Support\ApiResponse;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Back-office du module Formation (rôle ADMIN) : cours, leçons, vignettes.
 */
#[OA\Tag(name: 'Admin Courses', description: 'Gestion du catalogue Formation côté admin')]
class AdminCoursesController extends Controller
{
    public function __construct(private readonly CourseService $courses) {}

    public function paginate(Request $request)
    {
        $paginator = $this->courses->adminPaginate(
            (int) $request->query('page', 1),
            (int) $request->query('limit', 20),
            [
                'search' => $request->query('search'),
                'category' => $request->query('category'),
                'level' => $request->query('level'),
                'status' => $request->query('status'),
                'isFree' => $request->has('isFree') ? $request->boolean('isFree') : null,
            ],
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Course $course) => (new CourseResource($course))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    public function stats()
    {
        return ApiResponse::success($this->courses->stats());
    }

    public function show(string $id)
    {
        $course = $this->courseOrFail($id)->load('lessons');

        return ApiResponse::success([
            ...(new CourseResource($course))->resolve(),
            'lessons' => CourseLessonResource::collection($course->lessons)->resolve(),
        ]);
    }

    public function store(SaveCourseRequest $request)
    {
        $course = $this->courses->create($request->validated());

        return ApiResponse::success((new CourseResource($course))->resolve(), status: 201);
    }

    public function update(string $id, SaveCourseRequest $request)
    {
        $course = $this->courses->update($this->courseOrFail($id), $request->validated());

        return ApiResponse::success((new CourseResource($course))->resolve());
    }

    /**
     * Archive (is_active = false) plutôt que supprimer : les inscriptions,
     * progressions et certificats des apprenants sont conservés.
     */
    public function destroy(string $id)
    {
        $this->courses->delete($this->courseOrFail($id));

        return ApiResponse::success(null, 'Formation archivée');
    }

    public function uploadImage(Request $request)
    {
        $request->validate(['image' => ['required', 'image', 'max:5120']]);

        $path = $this->courses->storeImage($request->file('image'));

        return ApiResponse::success(['path' => $path, 'url' => MediaUrl::resolve($path)], status: 201);
    }

    /*
    |----------------------------------------------------------------------
    | Leçons
    |----------------------------------------------------------------------
    */

    public function lessons(string $id)
    {
        $course = $this->courseOrFail($id);

        return ApiResponse::success(CourseLessonResource::collection($course->lessons()->get())->resolve());
    }

    public function storeLesson(string $id, SaveCourseLessonRequest $request)
    {
        $lesson = $this->courses->createLesson($this->courseOrFail($id), $request->validated());

        return ApiResponse::success((new CourseLessonResource($lesson))->resolve(), status: 201);
    }

    public function updateLesson(string $lessonId, SaveCourseLessonRequest $request)
    {
        $lesson = $this->courses->updateLesson($this->lessonOrFail($lessonId), $request->validated());

        return ApiResponse::success((new CourseLessonResource($lesson))->resolve());
    }

    public function destroyLesson(string $lessonId)
    {
        $this->courses->deleteLesson($this->lessonOrFail($lessonId));

        return ApiResponse::success(null, 'Leçon supprimée');
    }

    public function reorderLessons(string $id, Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
        ]);

        $lessons = $this->courses->reorderLessons($this->courseOrFail($id), $data['ids']);

        return ApiResponse::success(CourseLessonResource::collection($lessons)->resolve());
    }

    private function courseOrFail(string $id): Course
    {
        return $this->courses->find($id) ?? abort(404, 'Formation introuvable');
    }

    private function lessonOrFail(string $id): CourseLesson
    {
        return $this->courses->findLesson($id) ?? abort(404, 'Leçon introuvable');
    }
}
