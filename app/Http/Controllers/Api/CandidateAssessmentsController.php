<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCandidateAssessmentRequest;
use App\Http\Resources\CandidateAssessmentResource;
use App\Models\Candidature;
use App\Models\JobOffer;
use App\Services\CandidateAssessmentReportService;
use App\Services\CandidateAssessmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Candidate Assessments', description: 'Évaluation IA des candidats en recrutement')]
class CandidateAssessmentsController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(
        private readonly CandidateAssessmentService $assessmentService,
        private readonly CandidateAssessmentReportService $reportService,
    ) {}

    /*
    |----------------------------------------------------------------------
    | GÉNÉRER / RÉGÉNÉRER L'ÉVALUATION D'UNE CANDIDATURE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/candidatures/{candidatureId}/assessment',
        tags: ['Candidate Assessments'],
        summary: "Génère (ou régénère) l'évaluation IA d'une candidature",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'candidatureId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCandidateAssessmentRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Évaluation générée', content: new OA\JsonContent(ref: '#/components/schemas/CandidateAssessment')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Réservé à l'entreprise propriétaire de l'offre"),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function store(string $candidatureId, CreateCandidateAssessmentRequest $request)
    {
        $candidature = $this->findCandidatureOrFail($candidatureId, $request);

        $assessment = $this->assessmentService->create($candidature, $request->validated()['exchangeNotes'] ?? null);

        return new CandidateAssessmentResource($assessment);
    }

    /*
    |----------------------------------------------------------------------
    | DÉTAIL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/candidatures/{candidatureId}/assessment',
        tags: ['Candidate Assessments'],
        summary: "Détail de l'évaluation IA d'une candidature",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'candidatureId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Évaluation trouvée', content: new OA\JsonContent(ref: '#/components/schemas/CandidateAssessment')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Réservé à l'entreprise propriétaire de l'offre"),
            new OA\Response(response: 404, description: 'Candidature ou évaluation introuvable'),
        ]
    )]
    public function show(string $candidatureId, Request $request)
    {
        $this->findCandidatureOrFail($candidatureId, $request);

        $assessment = $this->assessmentService->find($candidatureId);

        if (! $assessment) {
            abort(404, 'CandidateAssessment not found');
        }

        return new CandidateAssessmentResource($assessment);
    }

    /*
    |----------------------------------------------------------------------
    | RAPPORT TÉLÉCHARGEABLE (.docx, généré à la demande, streamé depuis un
    | disque privé — jamais exposé via une URL publique fetchable sans
    | revérification d'appartenance à l'entreprise à chaque appel).
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/candidatures/{candidatureId}/assessment/report',
        tags: ['Candidate Assessments'],
        summary: "Génère et télécharge le rapport d'évaluation .docx",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'candidatureId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fichier .docx', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Réservé à l'entreprise propriétaire de l'offre"),
            new OA\Response(response: 404, description: 'Candidature ou évaluation introuvable'),
        ]
    )]
    public function downloadReport(string $candidatureId, Request $request)
    {
        $candidature = $this->findCandidatureOrFail($candidatureId, $request);

        $assessment = $this->assessmentService->find($candidatureId);

        if (! $assessment) {
            abort(404, 'CandidateAssessment not found');
        }

        $relativePath = $this->reportService->generate($assessment);
        $assessment->update(['report_path' => $relativePath]);

        $downloadName = 'evaluation-'.str_replace(' ', '-', $candidature->candidate_name).'.docx';

        return Storage::disk('local')->download($relativePath, $downloadName);
    }

    /*
    |----------------------------------------------------------------------
    | COMPARAISON DES CANDIDATS POUR UNE MÊME OFFRE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/job-offers/{jobOfferId}/candidate-assessments/compare',
        tags: ['Candidate Assessments'],
        summary: "Classe les candidats évalués pour une même offre",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'jobOfferId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Classement (vide si moins de 2 candidats évalués)',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'rank', type: 'integer'),
                    new OA\Property(property: 'reason', type: 'string'),
                ]))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Réservé à l'entreprise propriétaire de l'offre"),
        ]
    )]
    public function compare(string $jobOfferId, Request $request)
    {
        $jobOffer = JobOffer::find($jobOfferId);

        if (! $jobOffer) {
            abort(404, 'JobOffer not found');
        }

        $this->authorizeOwnerOrAdmin($request->user(), $request->user()?->company_id === $jobOffer->company_id);

        return response()->json($this->assessmentService->compareForJobOffer($jobOfferId));
    }

    private function findCandidatureOrFail(string $candidatureId, Request $request): Candidature
    {
        $candidature = Candidature::with('jobOffer')->find($candidatureId);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        $this->authorizeOwnerOrAdmin(
            $request->user(),
            $request->user()?->company_id !== null && $request->user()?->company_id === $candidature->jobOffer?->company_id,
        );

        return $candidature;
    }
}
