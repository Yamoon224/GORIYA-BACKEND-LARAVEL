<?php

namespace App\Http\Controllers\Api;

use App\Enums\InterviewRecommendation;
use App\Enums\RecruitmentInterviewStatus;
use App\Enums\RecruitmentInterviewType;
use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Concerns\SplitsListQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecruitmentInterviewResource;
use App\Models\RecruitmentInterview;
use App\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Recruitment Interviews', description: 'Services RH — entretiens de recrutement')]
class RecruitmentInterviewsController extends Controller
{
    use ResolvesEnterpriseCompany, SplitsListQuery;

    public function __construct(private readonly RecruitmentService $recruitment) {}

    #[OA\Get(
        path: '/recruitment/interviews',
        tags: ['Recruitment Interviews'],
        summary: "Entretiens de l'entreprise, avec le candidat concerné",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'SCHEDULED')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Entretiens', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/RecruitmentInterview'))),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function index(Request $request)
    {
        $companyId = $this->enterpriseCompanyId($request);
        $this->splitListQuery($request, ['status']);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(RecruitmentInterviewStatus::class)],
        ]);

        return RecruitmentInterviewResource::collection($this->recruitment->listInterviews($companyId, $filters));
    }

    #[OA\Post(
        path: '/recruitment/candidates/{id}/interviews',
        tags: ['Recruitment Interviews'],
        summary: 'Planifie un entretien et, par défaut, convoque le candidat',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['PHONE', 'VIDEO', 'ONSITE']),
            new OA\Property(property: 'scheduledAt', type: 'string', format: 'date-time'),
            new OA\Property(property: 'durationMinutes', type: 'integer', example: 45),
            new OA\Property(property: 'location', type: 'string', nullable: true),
            new OA\Property(property: 'meetingUrl', type: 'string', nullable: true),
            new OA\Property(property: 'interviewers', type: 'string', nullable: true),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'notifyCandidate', type: 'boolean', example: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Entretien planifié', content: new OA\JsonContent(ref: '#/components/schemas/RecruitmentInterview')),
            new OA\Response(response: 400, description: 'Candidat embauché ou écarté'),
            new OA\Response(response: 404, description: 'Candidat introuvable'),
        ]
    )]
    public function store(string $id, Request $request)
    {
        $candidature = $this->recruitment->findCandidature($id, $this->enterpriseCompanyId($request));
        if (! $candidature) {
            abort(404, 'Candidat introuvable.');
        }

        $interview = $this->recruitment->scheduleInterview($candidature, $request->user(), $request->validate($this->rules(false), $this->messages()));

        return (new RecruitmentInterviewResource($interview))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/recruitment/interviews/{id}',
        tags: ['Recruitment Interviews'],
        summary: 'Modifie ou déplace un entretien planifié (le candidat est prévenu d\'un changement d\'horaire)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Entretien modifié', content: new OA\JsonContent(ref: '#/components/schemas/RecruitmentInterview')),
            new OA\Response(response: 400, description: 'Entretien déjà passé ou annulé'),
            new OA\Response(response: 404, description: 'Entretien introuvable'),
        ]
    )]
    public function update(string $id, Request $request)
    {
        $interview = $this->interviewOrFail($id, $request);

        return new RecruitmentInterviewResource(
            $this->recruitment->updateInterview($interview, $request->validate($this->rules(true), $this->messages())),
        );
    }

    #[OA\Patch(
        path: '/recruitment/interviews/{id}/outcome',
        tags: ['Recruitment Interviews'],
        summary: "Compte rendu d'un entretien : passé (note, avis), candidat absent, ou annulé",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['COMPLETED', 'NO_SHOW', 'CANCELLED']),
            new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5, nullable: true),
            new OA\Property(property: 'recommendation', type: 'string', enum: ['HIRE', 'MAYBE', 'NO_HIRE'], nullable: true),
            new OA\Property(property: 'feedback', type: 'string', nullable: true),
            new OA\Property(property: 'notifyCandidate', type: 'boolean', example: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Entretien mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/RecruitmentInterview')),
            new OA\Response(response: 400, description: 'Transition interdite'),
            new OA\Response(response: 404, description: 'Entretien introuvable'),
        ]
    )]
    public function outcome(string $id, Request $request)
    {
        $interview = $this->interviewOrFail($id, $request);
        $data = $request->validate([
            'status' => ['required', Rule::in([
                RecruitmentInterviewStatus::COMPLETED->value,
                RecruitmentInterviewStatus::NO_SHOW->value,
                RecruitmentInterviewStatus::CANCELLED->value,
            ])],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'recommendation' => ['nullable', Rule::enum(InterviewRecommendation::class)],
            'feedback' => ['nullable', 'string', 'max:5000'],
            'notifyCandidate' => ['nullable', 'boolean'],
        ]);

        return new RecruitmentInterviewResource($this->recruitment->recordOutcome($interview, $request->user(), $data));
    }

    #[OA\Delete(
        path: '/recruitment/interviews/{id}',
        tags: ['Recruitment Interviews'],
        summary: 'Supprime un entretien qui n\'a pas eu lieu',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Entretien supprimé'),
            new OA\Response(response: 400, description: 'Entretien passé avec compte rendu'),
            new OA\Response(response: 404, description: 'Entretien introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $this->recruitment->deleteInterview($this->interviewOrFail($id, $request));

        return response()->json(['message' => 'Entretien supprimé']);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::enum(RecruitmentInterviewType::class)],
            'scheduledAt' => [$required, 'date'],
            'durationMinutes' => ['nullable', 'integer', 'min:10', 'max:480'],
            'location' => ['nullable', 'string', 'max:255'],
            'meetingUrl' => ['nullable', 'url', 'max:500'],
            'interviewers' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notifyCandidate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'scheduledAt.required' => "Indiquez la date et l'heure de l'entretien.",
            'meetingUrl.url' => 'Le lien de visioconférence doit être une adresse web complète (https://…).',
            'durationMinutes.min' => "Un entretien dure au moins 10 minutes.",
        ];
    }

    private function interviewOrFail(string $id, Request $request): RecruitmentInterview
    {
        $interview = $this->recruitment->findInterview($id, $this->enterpriseCompanyId($request));
        if (! $interview) {
            abort(404, 'Entretien introuvable.');
        }

        return $interview;
    }
}
