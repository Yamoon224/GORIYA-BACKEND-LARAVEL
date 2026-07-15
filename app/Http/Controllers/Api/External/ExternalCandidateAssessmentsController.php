<?php

namespace App\Http\Controllers\Api\External;

use App\Http\Controllers\Controller;
use App\Http\Resources\CandidateAssessmentResource;
use App\Models\ApiClient;
use App\Models\Candidature;
use App\Services\CandidateAssessmentService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'External API', description: 'API B2B pour intégrations ATS/SIRH (authentification par clé)')]
class ExternalCandidateAssessmentsController extends Controller
{
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly CandidateAssessmentService $assessmentService,
    ) {}

    #[OA\Get(
        path: '/external/v1/candidatures/{candidatureId}/assessment',
        tags: ['External API'],
        summary: "Score d'évaluation IA d'un candidat (voir aussi le webhook candidate_assessment.completed)",
        security: [['apiKeyAuth' => []]],
        parameters: [new OA\Parameter(name: 'candidatureId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Évaluation trouvée', content: new OA\JsonContent(ref: '#/components/schemas/CandidateAssessment')),
            new OA\Response(response: 401, description: 'Clé API invalide'),
            new OA\Response(response: 404, description: 'Candidature ou évaluation introuvable'),
        ]
    )]
    public function show(string $candidatureId)
    {
        $candidature = Candidature::whereHas('jobOffer', fn ($q) => $q->where('company_id', $this->apiClient->company_id))
            ->find($candidatureId);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        $assessment = $this->assessmentService->find($candidatureId);
        if (! $assessment) {
            abort(404, 'CandidateAssessment not found');
        }

        return new CandidateAssessmentResource($assessment);
    }
}
