<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleAuthRequest;
use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth', description: "Authentification de l'espace utilisateur (candidats, entreprises)")]
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    #[OA\Post(
        path: '/auth/login',
        tags: ['Auth'],
        summary: 'Connexion par email/mot de passe',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connexion réussie',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'access_token', type: 'string'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Identifiants invalides ou compte bloqué', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        return response()->json($this->authService->login($data['email'], $data['password']));
    }

    #[OA\Post(
        path: '/auth/logout',
        tags: ['Auth'],
        summary: 'Déconnexion (invalide le JWT courant)',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Déconnexion effectuée (aucun échec possible, même sans token)',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Logged out successfully')])
            ),
        ]
    )]
    public function logout(Request $request)
    {
        return response()->json($this->authService->logout($request->header('Authorization')));
    }

    #[OA\Get(
        path: '/auth/profile',
        tags: ['Auth'],
        summary: "Profil (claims du JWT) de l'utilisateur courant",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Claims du token',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'role', type: 'string'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function profile()
    {
        return response()->json($this->authService->profile());
    }

    #[OA\Post(
        path: '/auth/refresh',
        tags: ['Auth'],
        summary: 'Rafraîchit le JWT (invalide immédiatement l\'ancien token)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nouveau token émis',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'token', type: 'string')])
            ),
            new OA\Response(response: 401, description: 'Non authentifié ou token déjà invalide'),
        ]
    )]
    public function refresh()
    {
        return response()->json(['token' => $this->authService->refresh()]);
    }

    #[OA\Post(
        path: '/auth/google',
        tags: ['Auth'],
        summary: "Connexion/inscription via Google (crée l'utilisateur s'il n'existe pas)",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/GoogleAuthRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connexion/inscription réussie',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    new OA\Property(property: 'isNewUser', type: 'boolean'),
                ])
            ),
        ]
    )]
    public function google(GoogleAuthRequest $request)
    {
        return response()->json($this->authService->googleAuth($request->validated()));
    }
}
