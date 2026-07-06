<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\AdminReportingService;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-students.controller.ts. "Students" =
 * utilisateurs role=USER. Les écritures (create/update) ne passent PAS par
 * un Form Request — parité avec le body `Record<string, unknown>` non
 * validé côté NestJS (seul POST /admin/users, dans AdminAuthController,
 * est validé).
 */
#[OA\Tag(name: 'Admin Students', description: "Gestion des étudiants (utilisateurs role=USER) côté admin")]
class AdminStudentsController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly AdminReportingService $adminReportingService,
    ) {}

    #[OA\Get(
        path: '/admin/students/paginate',
        tags: ['Admin Students'],
        summary: 'Recherche paginée des étudiants avec filtres (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
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
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->userService->paginate($page, $limit, [
            'name' => $request->query('search'),
            'status' => $request->query('status'),
            'role' => UserRole::USER->value,
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (User $user) => (new UserResource($user))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/students/stats',
        tags: ['Admin Students'],
        summary: 'Statistiques des étudiants (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'active', type: 'integer'),
                            new OA\Property(property: 'inactive', type: 'integer'),
                            new OA\Property(property: 'newThisMonth', type: 'integer'),
                        ],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function stats()
    {
        return ApiResponse::success($this->adminReportingService->getStudentStats());
    }

    #[OA\Get(
        path: '/admin/students/export',
        tags: ['Admin Students'],
        summary: 'Exporte les étudiants au format CSV (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fichier CSV (Content-Type: text/csv)',
                content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function export()
    {
        $csv = $this->adminReportingService->exportUsersCsv();

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="students.csv"')
            ->header('Content-Length', (string) strlen($csv));
    }

    #[OA\Get(
        path: '/admin/students/{id}',
        tags: ['Admin Students'],
        summary: "Détail d'un étudiant (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Étudiant trouvé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $user = User::with('company')->find($id);

        if (! $user) {
            abort(404, 'User not found');
        }

        return ApiResponse::success(new UserResource($user));
    }

    #[OA\Post(
        path: '/admin/students',
        tags: ['Admin Students'],
        summary: 'Crée un étudiant (rôle ADMIN requis, rôle forcé à USER, aucune validation FormRequest)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name', 'email', 'password'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'password', type: 'string', format: 'password'),
                        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
                        new OA\Property(property: 'companyId', type: 'string', format: 'uuid', nullable: true),
                        new OA\Property(property: 'avatar', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Étudiant créé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function store(Request $request)
    {
        $result = $this->userService->create(
            [...$request->except('avatar'), 'role' => UserRole::USER->value],
            $request->file('avatar'),
        );

        return ApiResponse::success(new UserResource($result['user']));
    }

    #[OA\Patch(
        path: '/admin/students/{id}',
        tags: ['Admin Students'],
        summary: 'Met à jour un étudiant (rôle ADMIN requis, aucune validation FormRequest)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(properties: [
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
                    new OA\Property(property: 'companyId', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'avatar', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
                ])
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Étudiant mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function update(string $id, Request $request)
    {
        $user = User::with('company')->find($id);

        if (! $user) {
            abort(404, 'User not found');
        }

        // Route role:ADMIN (voir routes/api.php) : l'appelant est déjà un admin,
        // donc role/status/companyId peuvent être modifiés ici sans risque.
        $updated = $this->userService->update($user, $request->except('avatar'), $request->file('avatar'), allowPrivilegedFields: true);

        return ApiResponse::success(new UserResource($updated));
    }

    #[OA\Patch(
        path: '/admin/students/{id}/status',
        tags: ['Admin Students'],
        summary: "Met à jour le statut d'un étudiant (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'])]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statut mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function updateStatus(string $id, Request $request)
    {
        $user = User::with('company')->find($id);

        if (! $user) {
            abort(404, 'User not found');
        }

        // Route role:ADMIN (voir routes/api.php) : l'appelant est déjà un admin.
        $updated = $this->userService->update($user, ['status' => $request->input('status')], null, allowPrivilegedFields: true);

        return ApiResponse::success(new UserResource($updated));
    }

    #[OA\Delete(
        path: '/admin/students/{id}',
        tags: ['Admin Students'],
        summary: 'Supprime un étudiant (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Étudiant supprimé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $user = User::find($id);

        if (! $user) {
            abort(404, 'User not found');
        }

        $this->userService->remove($user);

        return ApiResponse::success(null);
    }
}
