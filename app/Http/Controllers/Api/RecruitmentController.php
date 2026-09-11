<?php

namespace App\Http\Controllers\Api;

use App\Enums\RecruitmentStage;
use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Concerns\SplitsListQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecruitmentCandidateResource;
use App\Models\Candidature;
use App\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Recruitment', description: 'Services RH — pipeline de recrutement des candidatures reçues')]
class RecruitmentController extends Controller
{
    use ResolvesEnterpriseCompany, SplitsListQuery;

    public function __construct(private readonly RecruitmentService $recruitment) {}

    #[OA\Get(
        path: '/recruitment/job-offers',
        tags: ['Recruitment'],
        summary: "Offres de l'entreprise et répartition de leurs candidats par étape",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Offres'),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function jobOffers(Request $request)
    {
        return response()->json($this->recruitment->jobOffers($this->enterpriseCompanyId($request)));
    }

    #[OA\Get(
        path: '/recruitment/candidates',
        tags: ['Recruitment'],
        summary: 'Candidats du pipeline, toutes offres confondues',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'jobOfferId', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'stage', in: 'query', required: false, description: 'Une ou plusieurs étapes, séparées par des virgules', schema: new OA\Schema(type: 'string', example: 'NEW,SCREENING')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Nom, e-mail ou intitulé du poste', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Candidats', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/RecruitmentCandidate'))),
            new OA\Response(response: 400, description: 'Filtre invalide'),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function candidates(Request $request)
    {
        $companyId = $this->enterpriseCompanyId($request);
        $this->splitListQuery($request, ['stage']);

        $filters = $request->validate([
            'jobOfferId' => ['nullable', 'uuid'],
            'stage' => ['nullable', 'array'],
            'stage.*' => [Rule::enum(RecruitmentStage::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return RecruitmentCandidateResource::collection($this->recruitment->listCandidates($companyId, $filters));
    }

    #[OA\Get(
        path: '/recruitment/candidates/{id}',
        tags: ['Recruitment'],
        summary: 'Dossier complet d\'un candidat : candidature, entretiens, notes et historique',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Candidat', content: new OA\JsonContent(ref: '#/components/schemas/RecruitmentCandidate')),
            new OA\Response(response: 404, description: 'Candidat introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        return $this->detail($id, $request);
    }

    #[OA\Patch(
        path: '/recruitment/candidates/{id}/stage',
        tags: ['Recruitment'],
        summary: 'Déplace un candidat dans le pipeline (le statut vu par le candidat suit)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'stage', type: 'string', enum: ['NEW', 'SCREENING', 'INTERVIEW', 'OFFER', 'REJECTED']),
            new OA\Property(property: 'comment', type: 'string', nullable: true, description: 'Motif, conservé comme raison du refus'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Candidat déplacé', content: new OA\JsonContent(ref: '#/components/schemas/RecruitmentCandidate')),
            new OA\Response(response: 400, description: 'Candidat déjà embauché, ou embauche demandée hors fiche employé'),
            new OA\Response(response: 404, description: 'Candidat introuvable'),
        ]
    )]
    public function moveStage(string $id, Request $request)
    {
        $data = $request->validate([
            'stage' => ['required', Rule::enum(RecruitmentStage::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->recruitment->moveStage(
            $this->candidatureOrFail($id, $request),
            RecruitmentStage::from($data['stage']),
            $request->user(),
            $data['comment'] ?? null,
        );

        return $this->detail($id, $request);
    }

    #[OA\Post(
        path: '/recruitment/candidates/{id}/notes',
        tags: ['Recruitment'],
        summary: 'Ajoute une note de recruteur (invisible du candidat)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'body', type: 'string')])),
        responses: [
            new OA\Response(response: 201, description: 'Note ajoutée'),
            new OA\Response(response: 400, description: 'Note vide'),
            new OA\Response(response: 404, description: 'Candidat introuvable'),
        ]
    )]
    public function storeNote(string $id, Request $request)
    {
        $candidature = $this->candidatureOrFail($id, $request);
        $data = $request->validate(
            ['body' => ['required', 'string', 'max:5000']],
            ['body.required' => 'Écrivez la note avant de l\'enregistrer.'],
        );

        $note = $this->recruitment->addNote($candidature, $request->user(), trim($data['body']));

        return response()->json(RecruitmentCandidateResource::note($note), 201);
    }

    #[OA\Delete(
        path: '/recruitment/notes/{id}',
        tags: ['Recruitment'],
        summary: 'Supprime une note de recruteur',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Note supprimée'),
            new OA\Response(response: 404, description: 'Note introuvable'),
        ]
    )]
    public function destroyNote(string $id, Request $request)
    {
        $note = $this->recruitment->findNote($id, $this->enterpriseCompanyId($request));
        if (! $note) {
            abort(404, 'Note introuvable.');
        }

        $note->delete();

        return response()->json(['message' => 'Note supprimée']);
    }

    private function detail(string $id, Request $request): RecruitmentCandidateResource
    {
        $candidature = $this->recruitment->findCandidate($id, $this->enterpriseCompanyId($request));
        if (! $candidature) {
            abort(404, 'Candidat introuvable.');
        }

        return (new RecruitmentCandidateResource($candidature))->withDetail();
    }

    private function candidatureOrFail(string $id, Request $request): Candidature
    {
        $candidature = $this->recruitment->findCandidature($id, $this->enterpriseCompanyId($request));
        if (! $candidature) {
            abort(404, 'Candidat introuvable.');
        }

        return $candidature;
    }
}
