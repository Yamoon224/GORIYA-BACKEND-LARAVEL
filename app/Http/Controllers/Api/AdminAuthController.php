<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\AdminAuthService;
use App\Services\AuditLogService;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Mirroir de backend/src/admin/admin-auth.controller.ts. Contrairement aux
 * autres contrôleurs Admin, la plupart des routes ne sont PAS role:ADMIN —
 * seule POST /admin/users l'est (voir routes/api.php).
 */
#[OA\Tag(name: 'Admin Auth', description: "Authentification et gestion du profil de l'espace admin")]
class AdminAuthController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly AdminAuthService $adminAuthService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /*
    |----------------------------------------------------------------------
    | LOGIN — public, sans vérification de rôle (identique à AuthController::
    | login, juste réenveloppé). N'importe quel utilisateur peut obtenir un
    | token via cette route ; c'est le RolesGuard sur les routes suivantes
    | qui protège, pas celle-ci — parité avec @Public() sans @Roles côté Nest.
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/admin/auth/login',
        tags: ['Admin Auth'],
        summary: 'Connexion admin par email/mot de passe',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connexion réussie',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'access_token', type: 'string'),
                            new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                        ],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Identifiants invalides ou compte bloqué', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])
            ->select(['id', 'email', 'password', 'role', 'status', 'name'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->auditLogService->log('login_failed', null, [], ['email' => $data['email']]);
            abort(401, 'Invalid credentials');
        }

        if ($user->status === UserStatus::INACTIVE) {
            $this->auditLogService->log('login_failed', $user, [], ['email' => $data['email'], 'reason' => 'inactive'], actor: $user);
            abort(401, "Compte bloqué. Vous n'êtes pas autorisé à vous connecter. Veuillez contacter l'administrateur.");
        }

        $token = auth('api')->login($user);
        $fullUser = User::with('company')->findOrFail($user->id);

        $this->auditLogService->log('login', $fullUser, actor: $fullUser);

        return ApiResponse::success([
            'access_token' => $token,
            'user' => new UserResource($fullUser),
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | REGISTER (POST /admin/users) — role:ADMIN gated, réservé aux admins
    | déjà authentifiés. Seule route d'écriture Admin validée par un Form
    | Request (parité avec CreateUserDto côté Nest) ; allowAdminRole=true.
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/admin/users',
        tags: ['Admin Auth'],
        summary: 'Crée un utilisateur (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/CreateUserRequest')
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Utilisateur créé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'token', type: 'string'),
                            new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                        ],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function register(CreateUserRequest $request)
    {
        $result = $this->userService->create($request->validated(), $request->file('avatar'), allowAdminRole: true);

        return ApiResponse::success([
            'token' => $result['accessToken'],
            'user' => new UserResource($result['user']),
        ]);
    }

    #[OA\Post(
        path: '/admin/auth/logout',
        tags: ['Admin Auth'],
        summary: 'Déconnexion admin (invalide le JWT courant si fourni, aucun échec possible même sans token)',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Déconnexion effectuée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                    new OA\Property(property: 'message', type: 'string', example: 'Logged out successfully'),
                ])
            ),
        ]
    )]
    public function logout(Request $request)
    {
        $authHeader = $request->header('Authorization');

        if (! $authHeader) {
            return ApiResponse::success(null, 'No token provided');
        }

        $token = str_replace('Bearer ', '', $authHeader);

        try {
            $user = JWTAuth::setToken($token)->authenticate() ?: null;
            JWTAuth::setToken($token)->invalidate();
            $this->auditLogService->log('logout', $user, actor: $user);
        } catch (JWTException) {
            // Token déjà invalide/expiré : on considère la déconnexion réussie quand même.
        }

        return ApiResponse::success(null, 'Logged out successfully');
    }

    #[OA\Post(
        path: '/admin/auth/refresh',
        tags: ['Admin Auth'],
        summary: 'Rafraîchit le JWT admin (invalide immédiatement l\'ancien token)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nouveau token émis',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [new OA\Property(property: 'token', type: 'string')],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié ou token déjà invalide'),
        ]
    )]
    public function refresh()
    {
        $token = auth('api')->refresh();

        return ApiResponse::success(['token' => $token]);
    }

    #[OA\Post(
        path: '/admin/auth/verify-otp',
        tags: ['Admin Auth'],
        summary: "Vérifie l'OTP et retourne un JWT admin",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'code', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'OTP vérifié',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'token', type: 'string'),
                            new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                        ],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'OTP invalide'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        return ApiResponse::success($this->adminAuthService->verifyOtp($data['email'], $data['code'] ?? ''));
    }

    #[OA\Post(
        path: '/admin/auth/google',
        tags: ['Admin Auth'],
        summary: "Connexion/inscription admin via Google (crée l'utilisateur s'il n'existe pas)",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'firstName', type: 'string', nullable: true),
                    new OA\Property(property: 'lastName', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connexion/inscription réussie',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'token', type: 'string'),
                            new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                        ],
                        type: 'object'
                    ),
                ])
            ),
        ]
    )]
    public function googleLogin(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string'],
            'firstName' => ['nullable', 'string'],
            'lastName' => ['nullable', 'string'],
        ]);

        return ApiResponse::success($this->adminAuthService->loginWithGoogle($data));
    }

    #[OA\Get(
        path: '/admin/auth/profile',
        tags: ['Admin Auth'],
        summary: "Profil de l'admin courant",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profil courant',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function profile(Request $request)
    {
        return ApiResponse::success(new UserResource($request->user()->load('company')));
    }

    #[OA\Put(
        path: '/admin/user/profile',
        tags: ['Admin Auth'],
        summary: "Met à jour le profil de l'admin courant (name/email/password uniquement)",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profil mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function updateProfile(Request $request)
    {
        // Whitelist explicite : cette route édite TOUJOURS le compte de l'appelant
        // ($request->user()->id, pas un {id} de route), donc role/status/companyId
        // ne doivent jamais y être acceptés — même un admin ne doit pas pouvoir
        // s'auto-modifier ces champs par ce biais (utiliser la gestion admin dédiée).
        // Avant ce correctif, $request->all() transmettait tout tel quel, permettant
        // à n'importe quel utilisateur authentifié de s'auto-promouvoir ADMIN.
        $data = $request->only(['name', 'email', 'password']);

        return ApiResponse::success($this->adminAuthService->updateMyProfile($request->user()->id, $data));
    }

    #[OA\Post(
        path: '/admin/user/avatar',
        tags: ['Admin Auth'],
        summary: "Met à jour l'avatar de l'admin courant",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['avatar'],
                    properties: [
                        new OA\Property(property: 'avatar', type: 'string', format: 'binary', description: 'image/png, jpeg, jpg ou webp'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Avatar mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [new OA\Property(property: 'avatarUrl', type: 'string', nullable: true)],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function uploadAvatar(Request $request)
    {
        return ApiResponse::success($this->adminAuthService->uploadMyAvatar($request->user()->id, $request->file('avatar')));
    }
}
