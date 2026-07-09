<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateArticleRequest;
use App\Http\Requests\UpdateArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Services\ArticleService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Articles', description: 'Blog Goriya')]
class ArticlesController extends Controller
{
    public function __construct(private readonly ArticleService $articleService) {}

    #[OA\Get(
        path: '/articles',
        tags: ['Articles'],
        summary: 'Liste paginée des articles publiés',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 9)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Article')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
        ]
    )]
    public function index(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 9);

        $paginator = $this->articleService->paginate($page, $limit, publishedOnly: true);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Article $article) => (new ArticleResource($article))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/articles/{slug}',
        tags: ['Articles'],
        summary: 'Détail d\'un article publié par son slug',
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Article', content: new OA\JsonContent(ref: '#/components/schemas/Article')),
            new OA\Response(response: 404, description: 'Introuvable'),
        ]
    )]
    public function show(string $slug)
    {
        return new ArticleResource($this->articleService->findBySlug($slug, publishedOnly: true));
    }

    #[OA\Post(
        path: '/articles',
        tags: ['Articles'],
        summary: 'Crée un article (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/CreateArticleRequest'))
        ),
        responses: [
            new OA\Response(response: 201, description: 'Article créé', content: new OA\JsonContent(ref: '#/components/schemas/Article')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function store(CreateArticleRequest $request)
    {
        $article = $this->articleService->create($request->validated(), $request->file('coverImage'));

        return response()->json((new ArticleResource($article))->resolve(), 201);
    }

    #[OA\Get(
        path: '/admin/articles/paginate',
        tags: ['Articles'],
        summary: 'Liste paginée de tous les articles, brouillons compris (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Article')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
        ]
    )]
    public function adminPaginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->articleService->paginate($page, $limit, publishedOnly: false);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Article $article) => (new ArticleResource($article))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Patch(
        path: '/articles/{id}',
        tags: ['Articles'],
        summary: 'Met à jour un article (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/UpdateArticleRequest'))
        ),
        responses: [
            new OA\Response(response: 200, description: 'Article mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/Article')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Introuvable'),
        ]
    )]
    public function update(string $id, UpdateArticleRequest $request)
    {
        $article = Article::findOrFail($id);

        return new ArticleResource($this->articleService->update($article, $request->validated(), $request->file('coverImage')));
    }

    #[OA\Delete(
        path: '/articles/{id}',
        tags: ['Articles'],
        summary: 'Supprime un article (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Article supprimé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $article = Article::findOrFail($id);
        $this->articleService->remove($article);

        return response()->json(['message' => 'Article deleted successfully']);
    }
}
