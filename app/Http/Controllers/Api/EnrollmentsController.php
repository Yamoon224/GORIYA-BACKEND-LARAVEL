<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEnrollmentProgressRequest;
use App\Http\Resources\EnrollmentResource;
use App\Services\CourseService;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Enrollments', description: 'Inscriptions et progression sur les formations')]
class EnrollmentsController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
        private readonly CourseService $courseService,
    ) {}

    #[OA\Get(
        path: '/enrollments',
        tags: ['Enrollments'],
        summary: "Liste des inscriptions de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des inscriptions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Enrollment'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        return EnrollmentResource::collection($this->enrollmentService->listFor($request->user()));
    }

    #[OA\Post(
        path: '/courses/{courseId}/enroll',
        tags: ['Enrollments'],
        summary: 'Inscrit à une formation (idempotent)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'courseId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Inscription créée', content: new OA\JsonContent(ref: '#/components/schemas/Enrollment')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Formation introuvable'),
        ]
    )]
    public function enroll(string $courseId, Request $request)
    {
        $course = $this->courseService->find($courseId);

        if (! $course) {
            abort(404, 'Course not found');
        }

        $enrollment = $this->enrollmentService->enroll($request->user(), $course)->load('course');

        return new EnrollmentResource($enrollment);
    }

    #[OA\Patch(
        path: '/enrollments/{id}/progress',
        tags: ['Enrollments'],
        summary: 'Met à jour la progression (génère le certificat à 100%)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateEnrollmentProgressRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Progression mise à jour', content: new OA\JsonContent(ref: '#/components/schemas/Enrollment')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Inscription introuvable'),
        ]
    )]
    public function updateProgress(string $id, UpdateEnrollmentProgressRequest $request)
    {
        $enrollment = $this->enrollmentService->find($id, $request->user());

        if (! $enrollment) {
            abort(404, 'Enrollment not found');
        }

        $updated = $this->enrollmentService->updateProgress($enrollment, $request->validated()['progress']);

        return new EnrollmentResource($updated);
    }

    #[OA\Get(
        path: '/enrollments/{id}/certificate',
        tags: ['Enrollments'],
        summary: 'Télécharge le certificat de réussite (.docx)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fichier .docx'),
            new OA\Response(response: 400, description: 'Formation non terminée'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Inscription introuvable'),
        ]
    )]
    public function downloadCertificate(string $id, Request $request)
    {
        $enrollment = $this->enrollmentService->find($id, $request->user());

        if (! $enrollment) {
            abort(404, 'Enrollment not found');
        }

        if (! $enrollment->certificate_path) {
            abort(400, "Le certificat n'est disponible qu'une fois la formation terminée");
        }

        return Storage::disk('local')->download(
            $enrollment->certificate_path,
            'certificat-'.str_replace(' ', '-', $enrollment->course->title).'.docx',
        );
    }
}
