<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioResource;
use App\Models\Portfolio;
use App\Services\Admin\AdminReportingService;
use App\Services\PortfolioService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-portfolios.controller.ts.
 */
#[OA\Tag(name: 'Admin Portfolios', description: 'Gestion des portfolios candidats (rôle ADMIN requis)')]
class AdminPortfoliosController extends Controller
{
    public function __construct(
        private readonly PortfolioService $portfolioService,
        private readonly AdminReportingService $adminReportingService,
    ) {}

    #[OA\Get(
        path: '/admin/portfolios/stats',
        tags: ['Admin Portfolios'],
        summary: 'Statistiques des portfolios (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques calculées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'totalPortfolios', type: 'integer'),
                        new OA\Property(property: 'totalViews', type: 'integer'),
                        new OA\Property(property: 'totalDownloads', type: 'integer'),
                        new OA\Property(property: 'totalLikes', type: 'integer'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function stats()
    {
        return ApiResponse::success($this->adminReportingService->getPortfolioStats());
    }

    /*
    |----------------------------------------------------------------------
    | Quirk réel de la source : si `category` est fourni, le filtrage par
    | compétence se fait sur la page déjà paginée (result.data), pas sur
    | l'ensemble des portfolios — meta.total/totalPages sont recalculés à
    | partir de cette page filtrée uniquement. Ne pas "corriger" en un
    | filtre au niveau requête.
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/portfolios/paginate',
        tags: ['Admin Portfolios'],
        summary: 'Recherche paginée des portfolios (rôle ADMIN requis)',
        description: "Réponse brute (pas d'enveloppe ApiResponse::success). Si `category` est fourni, le filtrage se fait sur la page déjà paginée uniquement — meta.total/totalPages sont recalculés à partir de cette page filtrée.",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'search', in: 'query', description: 'Filtre sur le titre', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'query', description: 'Filtre par compétence, appliqué sur la page déjà paginée', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Portfolio')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);
        $category = $request->query('category');

        $paginator = $this->portfolioService->paginate($page, $limit, [
            'title' => $request->query('search'),
        ]);

        $items = $paginator->getCollection()->map(fn (Portfolio $portfolio) => (new PortfolioResource($portfolio))->resolve())->values();

        if (! $category) {
            return response()->json([
                'data' => $items->all(),
                'meta' => [
                    'total' => $paginator->total(),
                    'page' => $paginator->currentPage(),
                    'limit' => $paginator->perPage(),
                    'totalPages' => $paginator->lastPage(),
                ],
            ]);
        }

        $filtered = $items->filter(fn (array $item) => in_array($category, $item['skills'] ?? [], true))->values();

        return response()->json([
            'data' => $filtered->all(),
            'meta' => [
                'total' => $filtered->count(),
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'totalPages' => (int) (ceil($filtered->count() / max(1, $paginator->perPage())) ?: 1),
            ],
        ]);
    }

    #[OA\Get(
        path: '/admin/portfolios/featured',
        tags: ['Admin Portfolios'],
        summary: 'Portfolios mis en avant (6 max) (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des portfolios mis en avant',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Portfolio')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function featured()
    {
        return ApiResponse::success($this->adminReportingService->getFeaturedPortfolios());
    }

    #[OA\Get(
        path: '/admin/portfolios/categories',
        tags: ['Admin Portfolios'],
        summary: 'Comptage des portfolios par compétence (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Comptage par compétence',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'count', type: 'integer'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function categories()
    {
        return ApiResponse::success($this->adminReportingService->getPortfolioCategories());
    }

    #[OA\Get(
        path: '/admin/portfolios/{id}',
        tags: ['Admin Portfolios'],
        summary: "Détail d'un portfolio (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Portfolio trouvé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Portfolio'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Portfolio introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $portfolio = Portfolio::with('user')->find($id);

        if (! $portfolio) {
            abort(404, 'Portfolio not found');
        }

        return ApiResponse::success(new PortfolioResource($portfolio));
    }

    /*
    |----------------------------------------------------------------------
    | No-op réel de la source : le body {featured} est complètement ignoré,
    | il n'existe aucune colonne `featured` sur cette entité. Ne pas
    | implémenter un vrai feature-flag ici.
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/admin/portfolios/{id}/feature',
        tags: ['Admin Portfolios'],
        summary: 'Marque un portfolio comme mis en avant (rôle ADMIN requis)',
        description: "No-op réel de la source : le champ `featured` du body est complètement ignoré (aucune colonne `featured` n'existe sur cette entité) — le portfolio est renvoyé inchangé.",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'featured', type: 'boolean', description: 'Ignoré — aucun effet côté serveur'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Portfolio (inchangé)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Portfolio'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Portfolio introuvable'),
        ]
    )]
    public function feature(string $id)
    {
        $portfolio = Portfolio::with('user')->find($id);

        if (! $portfolio) {
            abort(404, 'Portfolio not found');
        }

        return ApiResponse::success(new PortfolioResource($portfolio));
    }

    #[OA\Delete(
        path: '/admin/portfolios/{id}',
        tags: ['Admin Portfolios'],
        summary: 'Supprime un portfolio (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Portfolio supprimé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Portfolio introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $portfolio = Portfolio::find($id);

        if (! $portfolio) {
            abort(404, 'Portfolio not found');
        }

        $portfolio->delete();

        return ApiResponse::success(null);
    }
}
