<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateApiClientRequest;
use App\Http\Requests\CreateWebhookRequest;
use App\Http\Resources\ApiClientResource;
use App\Http\Resources\WebhookResource;
use App\Models\ApiClient;
use App\Services\ApiClientService;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Gestion des identifiants API B2B côté entreprise (authentification JWT
 * normale) — distinct des contrôleurs Api\External\*, qui servent l'API
 * elle-même (authentification par clé, voir EnsureValidApiKey).
 */
#[OA\Tag(name: 'API Clients', description: 'Gestion des identifiants API B2B (ATS/SIRH)')]
class ApiClientsController extends Controller
{
    public function __construct(
        private readonly ApiClientService $apiClientService,
        private readonly WebhookService $webhookService,
    ) {}

    #[OA\Get(
        path: '/api-clients',
        tags: ['API Clients'],
        summary: "Liste des identifiants API de l'entreprise",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des identifiants',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ApiClient'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        $companyId = $this->requireCompanyId($request);

        return ApiClientResource::collection($this->apiClientService->listForCompany($companyId));
    }

    #[OA\Post(
        path: '/api-clients',
        tags: ['API Clients'],
        summary: 'Génère un nouvel identifiant API (jeton affiché une seule fois)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateApiClientRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Identifiant créé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'client', ref: '#/components/schemas/ApiClient'),
                    new OA\Property(property: 'token', type: 'string', description: "N'est plus jamais retourné après cet appel"),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function store(CreateApiClientRequest $request)
    {
        $companyId = $this->requireCompanyId($request);
        $data = $request->validated();

        $result = $this->apiClientService->create($companyId, $data['name'], $data['isSandbox'] ?? true);

        return response()->json([
            'client' => new ApiClientResource($result['client']),
            'token' => $result['token'],
        ]);
    }

    #[OA\Delete(
        path: '/api-clients/{id}',
        tags: ['API Clients'],
        summary: 'Révoque un identifiant API',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Identifiant révoqué'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Identifiant introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $companyId = $this->requireCompanyId($request);
        $client = $this->apiClientService->find($id, $companyId);

        if (! $client) {
            abort(404, 'ApiClient not found');
        }

        $this->apiClientService->revoke($client);

        return response()->json(['message' => 'ApiClient revoked']);
    }

    #[OA\Get(
        path: '/api-clients/{id}/webhooks',
        tags: ['API Clients'],
        summary: "Liste des webhooks de l'identifiant",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des webhooks',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Webhook'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Identifiant introuvable'),
        ]
    )]
    public function webhooks(string $id, Request $request)
    {
        $client = $this->findClientOrFail($id, $request);

        return WebhookResource::collection($this->webhookService->listForClient($client));
    }

    #[OA\Post(
        path: '/api-clients/{id}/webhooks',
        tags: ['API Clients'],
        summary: 'Crée un webhook (secret affiché une seule fois)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateWebhookRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Webhook créé', content: new OA\JsonContent(ref: '#/components/schemas/Webhook')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Identifiant introuvable'),
        ]
    )]
    public function storeWebhook(string $id, CreateWebhookRequest $request)
    {
        $client = $this->findClientOrFail($id, $request);
        $data = $request->validated();

        $webhook = $this->webhookService->create($client, $data['url'], $data['events']);

        return new WebhookResource($webhook);
    }

    #[OA\Delete(
        path: '/api-clients/{id}/webhooks/{webhookId}',
        tags: ['API Clients'],
        summary: 'Supprime un webhook',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'webhookId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Webhook supprimé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Identifiant ou webhook introuvable'),
        ]
    )]
    public function destroyWebhook(string $id, string $webhookId, Request $request)
    {
        $client = $this->findClientOrFail($id, $request);
        $webhook = $this->webhookService->listForClient($client)->firstWhere('id', $webhookId);

        if (! $webhook) {
            abort(404, 'Webhook not found');
        }

        $this->webhookService->delete($webhook);

        return response()->json(['message' => 'Webhook deleted successfully']);
    }

    private function requireCompanyId(Request $request): string
    {
        $companyId = $request->user()?->company_id;
        if (! $companyId) {
            abort(403, "Réservé aux comptes rattachés à une entreprise");
        }

        return $companyId;
    }

    private function findClientOrFail(string $id, Request $request): ApiClient
    {
        $companyId = $this->requireCompanyId($request);
        $client = $this->apiClientService->find($id, $companyId);

        if (! $client) {
            abort(404, 'ApiClient not found');
        }

        return $client;
    }
}
