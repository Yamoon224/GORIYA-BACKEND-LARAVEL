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
        return auth('api')->refresh();
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
     * @return array{access_token: string, user: UserResource, isNewUser: bool}
     */
    public function googleAuth(array $data): array
    {
        $existingUser = $this->userRepository->findByEmail($data['email']);

        if ($existingUser) {
            $token = auth('api')->login($existingUser);
            $fullUser = $this->userRepository->findOrFail($existingUser->id);
            $fullUser->load('company');

            return ['access_token' => $token, 'user' => new UserResource($fullUser), 'isNewUser' => false];
        }

        $user = $this->userRepository->create([
            'name' => $data['name'] ?: ($data['firstName'] ?? explode('@', $data['email'])[0]),
            'email' => $data['email'],
            'password' => Str::uuid()->toString(),
            'role' => UserRole::USER,
            'status' => UserStatus::ACTIVE,
            'avatar' => $data['picture'] ?? null,
        ]);

        $token = auth('api')->login($user);
        $fullUser = $this->userRepository->findOrFail($user->id);
        $fullUser->load('company');

        return ['access_token' => $token, 'user' => new UserResource($fullUser), 'isNewUser' => true];
    }
}
