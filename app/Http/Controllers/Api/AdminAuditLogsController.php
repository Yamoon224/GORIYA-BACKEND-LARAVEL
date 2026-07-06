<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Admin Audit Logs', description: "Journal d'audit des actions effectuées dans le système (rôle ADMIN requis)")]
class AdminAuditLogsController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    #[OA\Get(
        path: '/admin/audit-logs/paginate',
        tags: ['Admin Audit Logs'],
        summary: "Recherche paginée du journal d'audit (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'search', in: 'query', description: "Filtre sur le nom/l'email de l'auteur", schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'userId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'action', in: 'query', description: "ex: created, updated, deleted, login, login_failed, logout", schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'auditableType', in: 'query', description: 'Classe du modèle concerné (ex: App\\Models\\Company)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'auditableId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'dateFrom', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'dateTo', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuditLog')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->auditLogService->paginate($page, $limit, [
            'search' => $request->query('search'),
            'userId' => $request->query('userId'),
            'action' => $request->query('action'),
            'auditableType' => $request->query('auditableType'),
            'auditableId' => $request->query('auditableId'),
            'dateFrom' => $request->query('dateFrom'),
            'dateTo' => $request->query('dateTo'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($log) => (new AuditLogResource($log))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/audit-logs/actions',
        tags: ['Admin Audit Logs'],
        summary: "Liste des types d'action déjà journalisés (pour filtre) (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Types d'action distincts",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function actions()
    {
        return ApiResponse::success($this->auditLogService->distinctActions());
    }

    #[OA\Get(
        path: '/admin/audit-logs/{id}',
        tags: ['Admin Audit Logs'],
        summary: "Détail d'une entrée du journal d'audit (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entrée trouvée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/AuditLog'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Entrée introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $log = $this->auditLogService->find($id);

        if (! $log) {
            abort(404, 'Audit log not found');
        }

        return ApiResponse::success(new AuditLogResource($log));
    }
}
