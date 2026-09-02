<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\GoogleAuthRequest;
use App\Http\Requests\LinkedInAuthRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\AuthService;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        summary: 'Rafraîchit le JWT à partir du token courant (Bearer), même expiré tant qu\'il reste dans la fenêtre refresh_ttl. L\'ancien token est invalidé.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nouveau token émis',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'token', type: 'string')])
            ),
            new OA\Response(response: 401, description: 'Token absent, corrompu, ou hors fenêtre refresh_ttl'),
        ]
    )]
    public function refresh()
    {
        return response()->json(['token' => $this->authService->refresh()]);
    }

    #[OA\Post(
        path: '/auth/otp/request',
        tags: ['Auth'],
        summary: "Envoie (ou renvoie) un code OTP par email à un utilisateur existant",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'purpose', type: 'string', nullable: true, example: 'EMAIL_VERIFICATION'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Code envoyé'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
            new OA\Response(response: 429, description: 'Trop de demandes, réessaie plus tard'),
        ]
    )]
    public function requestOtp(Request $request)
    {
        // `purpose` est borné à la vérification d'email : les codes de
        // réinitialisation (PASSWORD_RESET) ont leurs propres endpoints, sinon
        // un code de reset pourrait être échangé ici contre un JWT.
        $data = $request->validate([
            'email' => ['required', 'email'],
            'purpose' => ['nullable', Rule::in([OtpService::PURPOSE_EMAIL_VERIFICATION])],
        ]);

        return response()->json($this->authService->requestOtp($data['email'], OtpService::PURPOSE_EMAIL_VERIFICATION));
    }

    #[OA\Post(
        path: '/auth/otp/verify',
        tags: ['Auth'],
        summary: "Vérifie un code OTP reçu par email et retourne un JWT",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'code'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'purpose', type: 'string', nullable: true, example: 'EMAIL_VERIFICATION'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Code vérifié',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 401, description: 'Code invalide ou expiré'),
            new OA\Response(response: 404, description: 'Utilisateur introuvable'),
        ]
    )]
    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            'purpose' => ['nullable', Rule::in([OtpService::PURPOSE_EMAIL_VERIFICATION])],
        ]);

        return response()->json($this->authService->verifyOtp($data['email'], $data['code'], OtpService::PURPOSE_EMAIL_VERIFICATION));
    }

    #[OA\Post(
        path: '/auth/password/forgot',
        tags: ['Auth'],
        summary: "Demande un code de réinitialisation de mot de passe par email",
        description: "Répond toujours 200 avec le même message, que le compte existe ou non, pour ne pas permettre d'énumérer les adresses inscrites.",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ForgotPasswordRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Demande prise en compte (code envoyé si le compte existe et est actif)',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])
            ),
        ]
    )]
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        return response()->json($this->authService->forgotPassword($request->validated()['email']));
    }

    #[OA\Post(
        path: '/auth/password/reset',
        tags: ['Auth'],
        summary: "Définit un nouveau mot de passe à partir du code reçu par email",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ResetPasswordRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Mot de passe réinitialisé',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])
            ),
            new OA\Response(response: 401, description: 'Code invalide ou expiré, ou compte bloqué', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Utilisateur introuvable', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function resetPassword(ResetPasswordRequest $request)
    {
        $data = $request->validated();

        return response()->json($this->authService->resetPassword($data['email'], $data['code'], $data['password']));
    }

    #[OA\Post(
        path: '/auth/google',
        tags: ['Auth'],
        summary: "Connexion/inscription via Google : le jeton `credential` est vérifié côté serveur auprès de Google, puis l'utilisateur est créé s'il n'existe pas (sauf allowSignup=false)",
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

    #[OA\Post(
        path: '/auth/linkedin',
        tags: ['Auth'],
        summary: "Connexion/inscription via LinkedIn : le `code` d'autorisation est échangé côté serveur contre le profil LinkedIn, puis l'utilisateur est créé s'il n'existe pas (sauf allowSignup=false)",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/LinkedInAuthRequest')
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
    public function linkedin(LinkedInAuthRequest $request)
    {
        return response()->json($this->authService->linkedinAuth($request->validated()));
    }
}
