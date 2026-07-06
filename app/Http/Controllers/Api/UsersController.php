<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Users', description: 'Gestion des utilisateurs (candidats)')]
class UsersController extends Controller
{
    public function __construct(private readonly UserService $userService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE (auto-inscription publique : le rôle ADMIN est explicitement
    | refusé, voir UserService::create)
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/users',
        tags: ['Users'],
        summary: 'Inscription publique (le rôle ADMIN est refusé même si fourni)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/CreateUserRequest')
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Utilisateur créé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    new OA\Property(property: 'accessToken', type: 'string'),
                ])
            ),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateUserRequest $request)
    {
        $result = $this->userService->create($request->validated(), $request->file('avatar'));

        return response()->json([
            'user' => new UserResource($result['user']),
            'accessToken' => $result['accessToken'],
        ], 201);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL (réservé aux admins)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/users',
        tags: ['Users'],
        summary: 'Liste complète des utilisateurs (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des utilisateurs',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/User'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: "Rôle ADMIN requis"),
        ]
    )]
    public function index()
    {
        $users = User::with('company')->get();

        return UserResource::collection($users);
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES (réservé aux admins)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/users/paginate',
        tags: ['Users'],
        summary: 'Recherche paginée avec filtres (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'name', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'email', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'role', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ADMIN', 'USER', 'ENTREPRISE'])),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
            new OA\Parameter(name: 'registrationDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'companyName', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'companyId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'companyStatus', in: 'query', schema: new OA\Schema(type: 'string')),
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
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->userService->paginate($page, $limit, [
            'name' => $request->query('name'),
            'email' => $request->query('email'),
            'role' => $request->query('role'),
            'status' => $request->query('status'),
            'registrationDate' => $request->query('registrationDate'),
            'companyName' => $request->query('companyName'),
            'companyId' => $request->query('companyId'),
            'companyStatus' => $request->query('companyStatus'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (User $user) => (new UserResource($user))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/users/{id}',
        tags: ['Users'],
        summary: 'Détail d\'un utilisateur (tout utilisateur authentifié, pas seulement le propriétaire — parité NestJS)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Utilisateur trouvé', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $user = User::with('company')->find($id);

        if (! $user) {
            abort(404, 'User not found');
        }

        return new UserResource($user);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/users/{id}',
        tags: ['Users'],
        summary: 'Met à jour un utilisateur (tout utilisateur authentifié, pas seulement le propriétaire — parité NestJS)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/UpdateUserRequest')
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Utilisateur mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function update(string $id, UpdateUserRequest $request)
    {
        $user = User::with('company')->find($id);

        if (! $user) {
            abort(404, 'User not found');
        }

        // role/status/companyId sont des champs privilégiés : seul un ADMIN peut
        // les modifier (via cette route ou une autre), qu'il s'agisse de son propre
        // compte ou de celui d'un tiers — sinon n'importe quel utilisateur authentifié
        // pourrait s'auto-promouvoir ADMIN en PATCHant son propre id.
        $isAdmin = $request->user()?->role === UserRole::ADMIN;

        $updated = $this->userService->update($user, $request->validated(), $request->file('avatar'), allowPrivilegedFields: $isAdmin);

        return new UserResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/users/{id}',
        tags: ['Users'],
        summary: 'Supprime un utilisateur (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Utilisateur supprimé', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'User deleted successfully')])),
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

        return response()->json(['message' => 'User deleted successfully']);
    }
}
