<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\Admin\AdminReportingService;
use App\Services\BookmarkService;
use App\Services\CompanyService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-companies.controller.ts. Les écritures
 * (create/update) ne passent pas par un Form Request — parité avec le body
 * `Record<string, unknown>` non validé côté NestJS.
 */
#[OA\Tag(name: 'Admin Companies', description: 'Gestion des entreprises côté admin')]
class AdminCompaniesController extends Controller
{
    public function __construct(
        private readonly CompanyService $companyService,
        private readonly AdminReportingService $adminReportingService,
        private readonly BookmarkService $bookmarkService,
    ) {}

    /**
     * Seule la première valeur est retenue quand plusieurs sont fournies
     * pour le même paramètre — quirk réel de la source (`asArray(value)[0]`),
     * copié tel quel plutôt que "corrigé" pour accepter tous les filtres.
     */
    private function asArray(mixed $value): array
    {
        if (! $value) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    #[OA\Get(
        path: '/admin/companies/paginate',
        tags: ['Admin Companies'],
        summary: 'Recherche paginée des entreprises avec filtres (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'industry', in: 'query', description: 'Filtre sector — seule la première valeur est retenue si un tableau est fourni', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'size', in: 'query', description: 'Filtre companySize — seule la première valeur est retenue si un tableau est fourni', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location', in: 'query', description: 'Seule la première valeur est retenue si un tableau est fourni', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Company')),
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

        $paginator = $this->companyService->paginate($page, $limit, [
            'name' => $request->query('search'),
            'sector' => $this->asArray($request->query('industry'))[0] ?? null,
            'companySize' => $this->asArray($request->query('size'))[0] ?? null,
            'location' => $this->asArray($request->query('location'))[0] ?? null,
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Company $company) => (new CompanyResource($company))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/companies/stats',
        tags: ['Admin Companies'],
        summary: 'Statistiques des entreprises (rôle ADMIN requis)',
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
        return ApiResponse::success($this->adminReportingService->getCompanyStats());
    }

    #[OA\Get(
        path: '/admin/companies/sectors',
        tags: ['Admin Companies'],
        summary: 'Répartition des entreprises par secteur (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Répartition par secteur',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'percentage', type: 'integer'),
                        ], type: 'object')
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function sectors()
    {
        return ApiResponse::success($this->adminReportingService->getCompanySectors());
    }

    #[OA\Post(
        path: '/admin/companies',
        tags: ['Admin Companies'],
        summary: 'Crée une entreprise (rôle ADMIN requis, aucune validation FormRequest)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['companyName', 'sector', 'email', 'password', 'partnershipDate'],
                    properties: [
                        new OA\Property(property: 'companyName', type: 'string'),
                        new OA\Property(property: 'sector', type: 'string'),
                        new OA\Property(property: 'about', type: 'string', nullable: true),
                        new OA\Property(property: 'creationDate', type: 'string', format: 'date', nullable: true),
                        new OA\Property(property: 'companySize', type: 'string', nullable: true),
                        new OA\Property(property: 'website', type: 'string', nullable: true),
                        new OA\Property(property: 'socialLinks', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                        new OA\Property(property: 'country', type: 'string', nullable: true),
                        new OA\Property(property: 'headquarters', type: 'string', nullable: true),
                        new OA\Property(property: 'location', type: 'string', nullable: true),
                        new OA\Property(property: 'phone', type: 'string', nullable: true),
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'password', type: 'string', format: 'password'),
                        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'SUSPENDED'], nullable: true),
                        new OA\Property(property: 'partnershipDate', type: 'string', format: 'date'),
                        new OA\Property(property: 'logo', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
                        new OA\Property(property: 'coverImage', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entreprise créée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Company'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function store(Request $request)
    {
        $result = $this->companyService->create($request->except(['logo', 'coverImage']), [
            'logo' => $request->file('logo'),
            'coverImage' => $request->file('coverImage'),
        ]);

        return ApiResponse::success(new CompanyResource($result['company']));
    }

    #[OA\Post(
        path: '/admin/companies/{companyId}/follow',
        tags: ['Admin Companies'],
        summary: "Ajoute l'entreprise aux favoris de l'admin courant (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'companyId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entreprise suivie',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function follow(string $companyId, Request $request)
    {
        $this->bookmarkService->followCompany($companyId, $request->user()->id);

        return ApiResponse::success(null);
    }

    #[OA\Delete(
        path: '/admin/companies/{companyId}/follow',
        tags: ['Admin Companies'],
        summary: "Retire l'entreprise des favoris de l'admin courant (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'companyId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entreprise retirée des favoris',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function unfollow(string $companyId, Request $request)
    {
        $this->bookmarkService->unfollowCompany($companyId, $request->user()->id);

        return ApiResponse::success(null);
    }

    #[OA\Get(
        path: '/me/followed-companies',
        tags: ['Admin Companies'],
        summary: "Liste des identifiants d'entreprises suivies par l'utilisateur courant",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Identifiants des entreprises suivies",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [new OA\Property(property: 'companyIds', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function followedCompanies(Request $request)
    {
        return ApiResponse::success(['companyIds' => $this->bookmarkService->followedCompanyIds($request->user()->id)]);
    }

    #[OA\Get(
        path: '/admin/companies/{companyId}/jobs',
        tags: ['Admin Companies'],
        summary: "Liste des offres d'emploi d'une entreprise (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'companyId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: "Offres d'emploi de l'entreprise",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function companyJobs(string $companyId)
    {
        return ApiResponse::success($this->adminReportingService->getCompanyJobs($companyId));
    }

    #[OA\Get(
        path: '/admin/companies/{id}',
        tags: ['Admin Companies'],
        summary: "Détail d'une entreprise (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entreprise trouvée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Company'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Entreprise introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $company = Company::with('users')->find($id);

        if (! $company) {
            abort(404, 'Company not found');
        }

        return ApiResponse::success(new CompanyResource($company));
    }

    #[OA\Patch(
        path: '/admin/companies/{id}',
        tags: ['Admin Companies'],
        summary: 'Met à jour une entreprise (rôle ADMIN requis, aucune validation FormRequest)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(properties: [
                    new OA\Property(property: 'companyName', type: 'string', nullable: true),
                    new OA\Property(property: 'sector', type: 'string', nullable: true),
                    new OA\Property(property: 'about', type: 'string', nullable: true),
                    new OA\Property(property: 'creationDate', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'companySize', type: 'string', nullable: true),
                    new OA\Property(property: 'website', type: 'string', nullable: true),
                    new OA\Property(property: 'socialLinks', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                    new OA\Property(property: 'country', type: 'string', nullable: true),
                    new OA\Property(property: 'headquarters', type: 'string', nullable: true),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'SUSPENDED'], nullable: true),
                    new OA\Property(property: 'partnershipDate', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'logo', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
                    new OA\Property(property: 'coverImage', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
                ])
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entreprise mise à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Company'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Entreprise introuvable'),
        ]
    )]
    public function update(string $id, Request $request)
    {
        $company = Company::find($id);

        if (! $company) {
            abort(404, 'Company not found');
        }

        $updated = $this->companyService->update($company, $request->except(['logo', 'coverImage']), [
            'logo' => $request->file('logo'),
            'coverImage' => $request->file('coverImage'),
        ]);

        return ApiResponse::success(new CompanyResource($updated));
    }

    #[OA\Patch(
        path: '/admin/companies/{id}/status',
        tags: ['Admin Companies'],
        summary: "Met à jour le statut d'une entreprise (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'SUSPENDED'])]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statut mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Company'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Entreprise introuvable'),
        ]
    )]
    public function updateStatus(string $id, Request $request)
    {
        $company = Company::find($id);

        if (! $company) {
            abort(404, 'Company not found');
        }

        $updated = $this->companyService->update($company, ['status' => $request->input('status')]);

        return ApiResponse::success(new CompanyResource($updated));
    }

    #[OA\Delete(
        path: '/admin/companies/{id}',
        tags: ['Admin Companies'],
        summary: 'Supprime une entreprise (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Entreprise supprimée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Entreprise introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $company = Company::find($id);

        if (! $company) {
            abort(404, 'Company not found');
        }

        $this->companyService->remove($company);

        return ApiResponse::success(null);
    }
}
