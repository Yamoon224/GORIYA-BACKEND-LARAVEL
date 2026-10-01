<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Http\Resources\EnrollmentResource;
use App\Models\CourseBookmark;
use App\Models\Enrollment;
use App\Services\CourseAccessService;
use App\Services\CourseService;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Espace apprenant du module Formation : « Mes cours » (progression),
 * progression par leçon, « Listes » (favoris) et pass découverte.
 */
#[OA\Tag(name: 'Learning', description: 'Espace apprenant du module Formation')]
class LearningController extends Controller
{
    public function __construct(
        private readonly CourseService $courses,
        private readonly EnrollmentService $enrollments,
        private readonly CourseAccessService $access,
    ) {}

    #[OA\Get(
        path: '/me/courses',
        tags: ['Learning'],
        summary: "Cours suivis par l'apprenant, avec la dernière leçon ouverte",
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Inscriptions')]
    )]
    public function myCourses(Request $request)
    {
        $user = $request->user();
        $enrollments = $this->enrollments->listFor($user);
        $lastLessons = $this->enrollments->lastLessonByCourse($user, $enrollments->pluck('course_id')->all());

        return response()->json($enrollments->map(fn (Enrollment $enrollment) => [
            ...(new EnrollmentResource($enrollment))->resolve(),
            'lastLessonId' => $lastLessons[$enrollment->course_id] ?? null,
        ])->values());
    }

    #[OA\Post(
        path: '/lessons/{id}/progress',
        tags: ['Learning'],
        summary: 'Enregistre la position de lecture / la complétion d\'une leçon',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'positionSeconds', type: 'integer'),
            new OA\Property(property: 'completed', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Progression enregistrée'),
            new OA\Response(response: 403, description: 'Leçon verrouillée (forfait ou pass requis)'),
            new OA\Response(response: 404, description: 'Leçon introuvable'),
        ]
    )]
    public function lessonProgress(string $id, Request $request)
    {
        $data = $request->validate([
            'positionSeconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'completed' => ['sometimes', 'boolean'],
        ]);

        $lesson = $this->courses->findLesson($id);
        if (! $lesson || ! $lesson->course?->is_active || ! $lesson->course->is_published) {
            abort(404, 'Lesson not found');
        }

        $user = $request->user();
        if (! $this->access->canWatch($user, $lesson->course, $lesson)) {
            abort(403, 'Cette leçon est réservée aux forfaits incluant les formations.');
        }

        $result = $this->enrollments->recordLessonProgress(
            $user,
            $lesson,
            $data['positionSeconds'] ?? null,
            (bool) ($data['completed'] ?? false),
        );

        return response()->json([
            'lessonId' => $lesson->id,
            'lastPositionSeconds' => $result['lessonProgress']->last_position_seconds,
            'completed' => $result['lessonProgress']->completed_at !== null,
            'enrollment' => (new EnrollmentResource($result['enrollment']))->resolve(),
        ]);
    }

    #[OA\Get(
        path: '/me/course-bookmarks',
        tags: ['Learning'],
        summary: 'Cours sauvegardés (« Listes »)',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Cours sauvegardés')]
    )]
    public function bookmarks(Request $request)
    {
        $bookmarks = CourseBookmark::where('user_id', $request->user()->id)
            ->whereHas('course', fn ($q) => $q->visible())
            ->with(['course.instructor', 'course.categoryRef'])
            ->orderByDesc('created_at')
            ->get();

        return CourseResource::collection($bookmarks->pluck('course'));
    }

    public function addBookmark(string $courseId, Request $request)
    {
        $course = $this->courses->findVisible($courseId);
        if (! $course) {
            abort(404, 'Course not found');
        }

        CourseBookmark::firstOrCreate(['user_id' => $request->user()->id, 'course_id' => $course->id]);

        return response()->json(['courseId' => $course->id, 'isBookmarked' => true], 201);
    }

    public function removeBookmark(string $courseId, Request $request)
    {
        CourseBookmark::where('user_id', $request->user()->id)->where('course_id', $courseId)->delete();

        return response()->json(['courseId' => $courseId, 'isBookmarked' => false]);
    }

    #[OA\Get(
        path: '/me/course-access',
        tags: ['Learning'],
        summary: "Droits d'accès au catalogue (forfait, pass découverte)",
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Résumé des droits')]
    )]
    public function access(Request $request)
    {
        return response()->json($this->access->summary($request->user()));
    }

    #[OA\Post(
        path: '/me/course-pass',
        tags: ['Learning'],
        summary: 'Active le pass découverte de 7 jours (une fois par compte)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Pass activé'),
            new OA\Response(response: 400, description: 'Pass déjà utilisé'),
        ]
    )]
    public function activatePass(Request $request)
    {
        $user = $request->user();
        $this->access->activatePass($user);

        return response()->json($this->access->summary($user), 201);
    }
}
