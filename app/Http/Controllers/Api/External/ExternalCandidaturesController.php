<?php

namespace App\Http\Controllers\Api\External;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExternalUpdateCandidatureStatusRequest;
use App\Http\Resources\CandidatureResource;
use App\Models\ApiClient;
use App\Models\Candidature;
use App\Services\CandidatureService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * API B2B (ATS/SIRH) — namespace /external/v1/*, authentifié par clé
 * (EnsureValidApiKey) plutôt que JWT. $apiClient est résolu par le
 * middleware et bindé dans le conteneur pour cette requête.
 */
#[OA\Tag(name: 'External API', description: 'API B2B pour intégrations ATS/SIRH (authentification par clé)')]
class ExternalCandidaturesController extends Controller
{
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly CandidatureService $candidatureService,
    ) {}

    #[OA\Get(
        path: '/external/v1/candidatures',
        tags: ['External API'],
        summary: "Liste paginée des candidatures de l'entreprise",
        security: [['apiKeyAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Candidature')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Clé API invalide'),
        ]
    )]
    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, (int) $request->query('limit', 20));

        // Filtre par entreprise non supporté par CandidatureRepository (pas
        // de besoin ailleurs dans l'app) — requête directe plutôt que
        // d'étendre un repository partagé, verrouillé par parité NestJS,
        // pour un seul consommateur externe.
        $paginator = Candidature::whereHas('jobOffer', fn ($q) => $q->where('company_id', $this->apiClient->company_id))
            ->with(['user', 'jobOffer.company'])
            ->orderByDesc('applied_date')
            ->paginate($limit, ['*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Candidature $c) => (new CandidatureResource($c))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/external/v1/candidatures/{id}',
        tags: ['External API'],
        summary: "Détail d'une candidature",
        security: [['apiKeyAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Candidature trouvée', content: new OA\JsonContent(ref: '#/components/schemas/Candidature')),
            new OA\Response(response: 401, description: 'Clé API invalide'),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function show(string $id)
    {
        return new CandidatureResource($this->findOrFail($id));
    }

    #[OA\Patch(
        path: '/external/v1/candidatures/{id}/status',
        tags: ['External API'],
        summary: "Met à jour le statut d'une candidature depuis l'ATS/SIRH partenaire",
        security: [['apiKeyAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ExternalUpdateCandidatureStatusRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Statut mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/Candidature')),
            new OA\Response(response: 401, description: 'Clé API invalide'),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function updateStatus(string $id, ExternalUpdateCandidatureStatusRequest $request)
    {
        $candidature = $this->findOrFail($id);

        // Réutilise CandidatureService::update() — la notification interne
        // et le webhook candidature.status_updated (voir WebhookService)
        // se déclenchent identiquement, que la mise à jour vienne de l'app
        // ou d'un partenaire ATS/SIRH.
        $updated = $this->candidatureService->update($candidature, $request->validated());

        return new CandidatureResource($updated);
    }

    private function findOrFail(string $id): Candidature
    {
        $candidature = Candidature::whereHas('jobOffer', fn ($q) => $q->where('company_id', $this->apiClient->company_id))
            ->with(['user', 'jobOffer.company'])
            ->find($id);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        return $candidature;
    }
}
