<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Resources\UserResource;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Mirroir de backend/src/auth/auth.service.ts. Extrait de AuthController
 * pour cohérence avec le reste du port (contrôleurs fins, logique métier
 * dans un Service).
 */
class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly AuditLogService $auditLogService,
        private readonly OtpService $otpService,
        private readonly GoogleTokenVerifier $googleTokenVerifier,
    ) {}

    /**
     * @return array{access_token: string, user: UserResource}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmailWithPassword($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->auditLogService->log('login_failed', null, [], ['email' => $email]);
            abort(401, 'Invalid credentials');
        }

        if ($user->status === UserStatus::INACTIVE) {
            $this->auditLogService->log('login_failed', $user, [], ['email' => $email, 'reason' => 'inactive'], actor: $user);
            abort(401, "Compte bloqué. Vous n'êtes pas autorisé à vous connecter. Veuillez contacter l'administrateur.");
        }

        // Tant que l'email n'est pas vérifié via OTP, la connexion d'un compte
        // candidat (USER) est refusée. Les comptes antérieurs à l'OTP ont été
        // rétro-marqués vérifiés par la migration. On renvoie un code frais pour
        // que l'utilisateur puisse finaliser sa vérification immédiatement.
        if ($user->role === UserRole::USER && $user->email_verified_at === null) {
            try {
                $this->otpService->send($user);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Renvoi OTP au login non vérifié échoué", [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $this->auditLogService->log('login_failed', $user, [], ['email' => $email, 'reason' => 'email_not_verified'], actor: $user);
            abort(403, 'EMAIL_NOT_VERIFIED');
        }

        $token = auth('api')->login($user);
        $fullUser = $this->userRepository->findOrFail($user->id);
        $fullUser->load('company');

        $this->auditLogService->log('login', $fullUser, actor: $fullUser);

        return ['access_token' => $token, 'user' => new UserResource($fullUser)];
    }

    /**
     * @return array{message: string}
     */
    public function logout(?string $authHeader): array
    {
        if (! $authHeader) {
            return ['message' => 'No token provided'];
        }

        $token = str_replace('Bearer ', '', $authHeader);

        try {
            $user = JWTAuth::setToken($token)->authenticate() ?: null;
            JWTAuth::setToken($token)->invalidate();
            $this->auditLogService->log('logout', $user, actor: $user);
        } catch (JWTException) {
            // Token déjà invalide/expiré : on considère la déconnexion réussie quand même.
        }

        return ['message' => 'Logged out successfully'];
    }

    /**
     * @return array{id: mixed, email: mixed, role: mixed}
     */
    public function profile(): array
    {
        $payload = auth('api')->payload();

        return [
            'id' => $payload->get('sub'),
            'email' => $payload->get('email'),
            'role' => $payload->get('role'),
        ];
    }

    public function refresh(): string
    {
        // Pas de middleware auth:api sur la route : on tolère un token expiré
        // tant qu'il reste dans la fenêtre refresh_ttl (rotation de session
        // NextAuth côté front). Au-delà, ou token absent/corrompu → 401.
        try {
            return auth('api')->refresh();
        } catch (JWTException) {
            abort(401, 'Session expirée. Veuillez vous reconnecter.');
        }
    }

    /**
     * @return array{message: string}
     */
    public function requestOtp(string $email, string $purpose = 'EMAIL_VERIFICATION'): array
    {
        $user = $this->userRepository->findByEmail($email);
        if (! $user) {
            abort(404, 'Utilisateur introuvable');
        }

        $this->otpService->send($user, $purpose);

        return ['message' => 'Code envoyé par email'];
    }

    /**
     * @return array{access_token: string, user: UserResource}
     */
    public function verifyOtp(string $email, string $code, string $purpose = 'EMAIL_VERIFICATION'): array
    {
        $user = $this->otpService->verify($email, $code, $purpose);

        $token = auth('api')->login($user);
        $fullUser = $this->userRepository->findOrFail($user->id);
        $fullUser->load('company');

        $this->auditLogService->log('login', $fullUser, actor: $fullUser);

        return ['access_token' => $token, 'user' => new UserResource($fullUser)];
    }

    /**
     * @param  array{credential: string, allowSignup?: bool}  $data
     * @return array{access_token: string, user: UserResource, isNewUser: bool}
     */
    public function googleAuth(array $data): array
    {
        $profile = $this->googleTokenVerifier->verify(
            $data['credential'],
            config('services.google.client_ids', []),
        );

        $existingUser = $this->userRepository->findByEmail($profile['email']);

        if ($existingUser) {
            if ($existingUser->status === UserStatus::INACTIVE) {
                abort(401, "Compte bloqué. Vous n'êtes pas autorisé à vous connecter. Veuillez contacter l'administrateur.");
            }

            $token = auth('api')->login($existingUser);
            $fullUser = $this->userRepository->findOrFail($existingUser->id);
            $fullUser->load('company');

            $this->auditLogService->log('login', $fullUser, [], ['provider' => 'google'], actor: $fullUser);

            return ['access_token' => $token, 'user' => new UserResource($fullUser), 'isNewUser' => false];
        }

        // Certains fronts (espace entreprise) n'autorisent la connexion Google
        // que pour un compte déjà existant — la création d'un compte entreprise
        // passe par un autre parcours (choix d'offre, création de la société).
        if (($data['allowSignup'] ?? true) === false) {
            abort(404, "Aucun compte n'est associé à cette adresse Google.");
        }

        $user = $this->userRepository->create([
            'name' => $profile['name'] ?: ($profile['given_name'] ?? explode('@', $profile['email'])[0]),
            'email' => $profile['email'],
            'password' => Str::uuid()->toString(),
            'role' => UserRole::USER,
            'status' => UserStatus::ACTIVE,
            'avatar' => $profile['picture'] ?? null,
        ]);

        // L'email est déjà vérifié par Google (claim email_verified contrôlé
        // dans GoogleTokenVerifier) — pas d'OTP à repasser.
        $user->forceFill(['email_verified_at' => now()])->save();

        $token = auth('api')->login($user);
        $fullUser = $this->userRepository->findOrFail($user->id);
        $fullUser->load('company');

        $this->auditLogService->log('register', $fullUser, [], ['provider' => 'google'], actor: $fullUser);

        return ['access_token' => $token, 'user' => new UserResource($fullUser), 'isNewUser' => true];
    }
}
