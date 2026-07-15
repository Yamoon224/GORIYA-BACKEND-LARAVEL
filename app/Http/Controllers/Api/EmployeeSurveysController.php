<?php

namespace App\Http\Controllers\Api;

use App\Enums\SurveyStatus;
use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEmployeeSurveyRequest;
use App\Http\Requests\SubmitSurveyResponseRequest;
use App\Http\Requests\UpdateSurveyStatusRequest;
use App\Http\Resources\EmployeeSurveyResource;
use App\Models\EmployeeSurvey;
use App\Services\EmployeeSurveyService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Employee Surveys', description: 'Enquêtes internes anonymes (satisfaction/évaluation des employés)')]
class EmployeeSurveysController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(private readonly EmployeeSurveyService $surveyService) {}

    #[OA\Get(
        path: '/employee-surveys',
        tags: ['Employee Surveys'],
        summary: "Liste des enquêtes de l'entreprise de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des enquêtes',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EmployeeSurvey'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        $companyId = $this->requireCompanyId($request);

        return EmployeeSurveyResource::collection($this->surveyService->listForCompany($companyId));
    }

    #[OA\Post(
        path: '/employee-surveys',
        tags: ['Employee Surveys'],
        summary: 'Crée une enquête (statut DRAFT)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateEmployeeSurveyRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Enquête créée', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeSurvey')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateEmployeeSurveyRequest $request)
    {
        $this->requireCompanyId($request);

        $survey = $this->surveyService->create($request->user(), $request->validated());

        return new EmployeeSurveyResource($survey);
    }

    #[OA\Get(
        path: '/employee-surveys/{id}',
        tags: ['Employee Surveys'],
        summary: "Détail d'une enquête",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Enquête trouvée', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeSurvey')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Enquête introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        $survey = $this->findSurveyOrFail($id, $request);

        return new EmployeeSurveyResource($survey);
    }

    #[OA\Patch(
        path: '/employee-surveys/{id}/status',
        tags: ['Employee Surveys'],
        summary: "Change le statut de l'enquête (DRAFT/ACTIVE/CLOSED)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateSurveyStatusRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Statut mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeSurvey')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Enquête introuvable'),
        ]
    )]
    public function updateStatus(string $id, UpdateSurveyStatusRequest $request)
    {
        $survey = $this->findSurveyOrFail($id, $request);
        $updated = $this->surveyService->updateStatus($survey, SurveyStatus::from($request->validated()['status']));

        return new EmployeeSurveyResource($updated);
    }

    #[OA\Get(
        path: '/employee-surveys/{id}/stats',
        tags: ['Employee Surveys'],
        summary: 'Statistiques agrégées (jamais de réponse individuelle)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Statistiques agrégées'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Enquête introuvable'),
        ]
    )]
    public function stats(string $id, Request $request)
    {
        $survey = $this->findSurveyOrFail($id, $request);

        return response()->json($this->surveyService->stats($survey));
    }

    #[OA\Post(
        path: '/employee-surveys/{id}/responses',
        tags: ['Employee Surveys'],
        summary: "Répond anonymement à l'enquête (une seule fois par employé)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SubmitSurveyResponseRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Réponse enregistrée'),
            new OA\Response(response: 400, description: "Enquête non ouverte aux réponses"),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Réservé aux employés de l'entreprise concernée"),
            new OA\Response(response: 404, description: 'Enquête introuvable'),
            new OA\Response(response: 409, description: 'Réponse déjà soumise'),
        ]
    )]
    public function submitResponse(string $id, SubmitSurveyResponseRequest $request)
    {
        $user = $request->user();
        $survey = EmployeeSurvey::find($id);

        if (! $survey) {
            abort(404, 'EmployeeSurvey not found');
        }

        $this->authorizeOwnerOrAdmin($user, $user->company_id === $survey->company_id);

        $this->surveyService->submitResponse($survey, $user, $request->validated()['answers']);

        return response()->json(['message' => 'Response submitted']);
    }

    #[OA\Delete(
        path: '/employee-surveys/{id}',
        tags: ['Employee Surveys'],
        summary: 'Supprime une enquête',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Enquête supprimée'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Enquête introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $survey = $this->findSurveyOrFail($id, $request);
        $this->surveyService->delete($survey);

        return response()->json(['message' => 'EmployeeSurvey deleted successfully']);
    }

    private function requireCompanyId(Request $request): string
    {
        $companyId = $request->user()?->company_id;
        if (! $companyId) {
            abort(403, "Réservé aux comptes rattachés à une entreprise");
        }

        return $companyId;
    }

    private function findSurveyOrFail(string $id, Request $request): EmployeeSurvey
    {
        $companyId = $this->requireCompanyId($request);
        $survey = $this->surveyService->find($id, $companyId);

        if (! $survey) {
            abort(404, 'EmployeeSurvey not found');
        }

        return $survey;
    }
}
