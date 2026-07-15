<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Community;
use App\Models\Post;
use App\Services\PostService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Posts', description: "GORIYA Connect — fil d'actualité")]
class PostsController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(private readonly PostService $postService) {}

    #[OA\Get(
        path: '/posts/feed',
        tags: ['Posts'],
        summary: "Fil d'actualité (suivis + communautés rejointes + soi-même)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page du fil',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Post')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function feed(Request $request)
    {
        $paginator = $this->postService->feedFor(
            $request->user(),
            (int) $request->query('page', 1),
            (int) $request->query('limit', 10),
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Post $post) => (new PostResource($post))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Post(
        path: '/posts',
        tags: ['Posts'],
        summary: 'Publie un post (profil ou communauté)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreatePostRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Post créé', content: new OA\JsonContent(ref: '#/components/schemas/Post')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Réservé aux membres de la communauté"),
        ]
    )]
    public function store(CreatePostRequest $request)
    {
        $data = $request->validated();
        $community = ! empty($data['communityId']) ? Community::find($data['communityId']) : null;

        $post = $this->postService->create($request->user(), $data['content'], $community);

        return new PostResource($post->load('user')->loadCount('likes'));
    }

    #[OA\Post(
        path: '/posts/{id}/like',
        tags: ['Posts'],
        summary: 'Bascule le like sur un post',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Like basculé', content: new OA\JsonContent(properties: [new OA\Property(property: 'liked', type: 'boolean')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Post introuvable'),
        ]
    )]
    public function toggleLike(string $id, Request $request)
    {
        $post = Post::find($id);
        if (! $post) {
            abort(404, 'Post not found');
        }

        $liked = $this->postService->toggleLike($post, $request->user());

        return response()->json(['liked' => $liked]);
    }

    #[OA\Delete(
        path: '/posts/{id}',
        tags: ['Posts'],
        summary: 'Supprime un post',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Post supprimé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Réservé au propriétaire du post'),
            new OA\Response(response: 404, description: 'Post introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $post = Post::find($id);
        if (! $post) {
            abort(404, 'Post not found');
        }

        $this->authorizeOwnerOrAdmin($request->user(), $request->user()->id === $post->user_id);

        $this->postService->delete($post);

        return response()->json(['message' => 'Post deleted successfully']);
    }
}
