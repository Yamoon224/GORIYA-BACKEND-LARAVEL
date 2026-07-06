<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePortfolioRequest;
use App\Http\Requests\UpdatePortfolioRequest;
use App\Http\Resources\PortfolioResource;
use App\Models\Portfolio;
use App\Services\PortfolioService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Portfolios', description: 'Gestion des portfolios des candidats')]
class PortfoliosController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(private readonly PortfolioService $portfolioService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/portfolios',
        tags: ['Portfolios'],
        summary: 'Crée un portfolio',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreatePortfolioRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Portfolio créé', content: new OA\JsonContent(ref: '#/components/schemas/Portfolio')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreatePortfolioRequest $request)
    {
        $portfolio = $this->portfolioService->create($request->validated());

        return new PortfolioResource($portfolio);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL (portfolios consultables publiquement, comme une vitrine)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/portfolios',
        tags: ['Portfolios'],
        summary: 'Liste complète des portfolios',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des portfolios',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Portfolio'))
            ),
        ]
    )]
    public function index()
    {
        $portfolios = Portfolio::with('user')->get();

        return PortfolioResource::collection($portfolios);
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES (public)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/portfolios/paginate',
        tags: ['Portfolios'],
        summary: 'Recherche paginée des portfolios avec filtres',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'title', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'description', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'skills', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'views', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'downloads', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'likes', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'createdDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'userId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
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
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->portfolioService->paginate($page, $limit, [
            'title' => $request->query('title'),
            'description' => $request->query('description'),
            'skills' => $request->query('skills'),
            'views' => $request->has('views') ? $request->query('views') : null,
            'downloads' => $request->has('downloads') ? $request->query('downloads') : null,
            'likes' => $request->has('likes') ? $request->query('likes') : null,
            'createdDate' => $request->query('createdDate'),
            'userId' => $request->query('userId'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Portfolio $portfolio) => (new PortfolioResource($portfolio))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/portfolios/{id}',
        tags: ['Portfolios'],
        summary: "Détail d'un portfolio",
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Portfolio trouvé', content: new OA\JsonContent(ref: '#/components/schemas/Portfolio')),
            new OA\Response(response: 404, description: 'Portfolio introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $portfolio = Portfolio::with('user')->find($id);

        if (! $portfolio) {
            abort(404, 'Portfolio not found');
        }

        return new PortfolioResource($portfolio);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/portfolios/{id}',
        tags: ['Portfolios'],
        summary: 'Met à jour un portfolio',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePortfolioRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Portfolio mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/Portfolio')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Portfolio introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdatePortfolioRequest $request)
    {
        $portfolio = Portfolio::with('user')->find($id);

        if (! $portfolio) {
            abort(404, 'Portfolio not found');
        }

        $this->authorizeOwnerOrAdmin($request->user(), $request->user()?->id === $portfolio->user_id);

        $updated = $this->portfolioService->update($portfolio, $request->validated());

        return new PortfolioResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/portfolios/{id}',
        tags: ['Portfolios'],
        summary: 'Supprime un portfolio',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Portfolio supprimé', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Portfolio deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Portfolio introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $portfolio = Portfolio::find($id);

        if (! $portfolio) {
            abort(404, 'Portfolio not found');
        }

        $this->authorizeOwnerOrAdmin($request->user(), $request->user()?->id === $portfolio->user_id);

        $this->portfolioService->remove($portfolio);

        return response()->json(['message' => 'Portfolio deleted successfully']);
    }
}
