<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePostRequest;
use App\Http\Resources\PostCommentResource;
use App\Http\Resources\PostResource;
use App\Models\Community;
use App\Models\Post;
use App\Models\PostComment;
use App\Services\PostService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Posts', description: "GORIYA Connect — fil d'actualité")]
class PostsController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(private readonly PostService $postService) {}

    #[OA\Get(
        path: '/posts/feed',
        tags: ['Posts'],
        summary: "Fil d'actualité (posts publics des membres + communautés rejointes)",
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
            $paginator->getCollection()->map(fn (Post $post) => (new PostResource($post))->resolve($request))
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Post(
        path: '/posts',
        tags: ['Posts'],
        summary: 'Publie un post (texte, images ou PDF — profil ou communauté)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/CreatePostRequest'))
        ),
        responses: [
            new OA\Response(response: 200, description: 'Post créé', content: new OA\JsonContent(ref: '#/components/schemas/Post')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Réservé aux membres de la communauté'),
            new OA\Response(response: 422, description: 'Post vide, format ou nombre de pièces jointes refusé'),
        ]
    )]
    public function store(CreatePostRequest $request)
    {
        $data = $request->validated();
        $community = ! empty($data['communityId']) ? Community::find($data['communityId']) : null;

        $post = $this->postService->create(
            $request->user(),
            $data['content'] ?? null,
            $community,
            array_values(array_filter(Arr::wrap($request->file('attachments', [])))),
        );

        return new PostResource($this->postService->loadForViewer($post, $request->user()));
    }

    #[OA\Get(
        path: '/posts/{id}',
        tags: ['Posts'],
        summary: "Détail d'un post (lien partagé)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Post', content: new OA\JsonContent(ref: '#/components/schemas/Post')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Post introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        $post = $this->findOrFail($id);

        if (! $this->postService->canView($request->user(), $post)) {
            abort(404, 'Post introuvable');
        }

        return new PostResource($this->postService->loadForViewer($post, $request->user()));
    }

    #[OA\Post(
        path: '/posts/{id}/like',
        tags: ['Posts'],
        summary: 'Bascule le like sur un post',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Like basculé', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'liked', type: 'boolean'),
                new OA\Property(property: 'likesCount', type: 'integer'),
            ])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Post introuvable'),
        ]
    )]
    public function toggleLike(string $id, Request $request)
    {
        $post = $this->findOrFail($id);

        $liked = $this->postService->toggleLike($post, $request->user());

        return response()->json(['liked' => $liked, 'likesCount' => $post->likes()->count()]);
    }

    #[OA\Post(
        path: '/posts/{id}/repost',
        tags: ['Posts'],
        summary: 'Republie un post, avec ou sans commentaire',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'content', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Republication créée', content: new OA\JsonContent(ref: '#/components/schemas/Post')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Post introuvable'),
            new OA\Response(response: 422, description: 'Déjà republié, ou publication de communauté'),
        ]
    )]
    public function repost(string $id, Request $request)
    {
        $data = $request->validate(['content' => ['nullable', 'string', 'max:3000']]);

        $repost = $this->postService->repost($request->user(), $this->findOrFail($id), $data['content'] ?? null);

        return new PostResource($this->postService->loadForViewer($repost, $request->user()));
    }

    #[OA\Get(
        path: '/posts/{id}/comments',
        tags: ['Posts'],
        summary: "Commentaires d'un post, du plus récent au plus ancien",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page de commentaires', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PostComment')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Post introuvable'),
        ]
    )]
    public function comments(string $id, Request $request)
    {
        $paginator = $this->postService->comments(
            $request->user(),
            $this->findOrFail($id),
            (int) $request->query('page', 1),
            (int) $request->query('limit', 10),
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (PostComment $comment) => (new PostCommentResource($comment))->resolve($request))
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Post(
        path: '/posts/{id}/comments',
        tags: ['Posts'],
        summary: 'Commente un post',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['content'], properties: [
            new OA\Property(property: 'content', type: 'string', maxLength: 1250),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Commentaire créé', content: new OA\JsonContent(ref: '#/components/schemas/PostComment')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Post introuvable'),
        ]
    )]
    public function storeComment(string $id, Request $request)
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:1250']]);

        return new PostCommentResource(
            $this->postService->addComment($request->user(), $this->findOrFail($id), $data['content'])
        );
    }

    #[OA\Delete(
        path: '/posts/{id}/comments/{commentId}',
        tags: ['Posts'],
        summary: "Supprime un commentaire (son auteur, l'auteur du post ou un admin)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'commentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Commentaire supprimé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Non autorisé'),
            new OA\Response(response: 404, description: 'Commentaire introuvable'),
        ]
    )]
    public function destroyComment(string $id, string $commentId, Request $request)
    {
        $comment = PostComment::where('post_id', $id)->find($commentId);
        if (! $comment) {
            abort(404, 'Commentaire introuvable');
        }

        $this->postService->deleteComment($request->user(), $comment);

        return response()->json(['message' => 'Comment deleted successfully']);
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
        $post = $this->findOrFail($id);

        $this->authorizeOwnerOrAdmin($request->user(), $request->user()->id === $post->user_id);

        $this->postService->delete($post);

        return response()->json(['message' => 'Post deleted successfully']);
    }

    private function findOrFail(string $id): Post
    {
        $post = Post::find($id);
        if (! $post) {
            abort(404, 'Post not found');
        }

        return $post;
    }
}
