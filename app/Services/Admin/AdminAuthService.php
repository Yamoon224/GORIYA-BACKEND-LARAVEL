<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Http\Resources\UserResource;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\OtpService;
use App\Services\UserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Mirroir du sous-ensemble "auth admin" de backend/src/admin/admin-platform.service.ts
 * (verifyOtp/loginWithGoogle/buildTokenResponse/updateMyProfile/uploadMyAvatar).
 * Extrait de l'ex-AdminPlatformService (une seule responsabilité : les flux
 * d'authentification/profil admin, distincts de AuthService qui gère l'auth
 * publique — règles différentes : pas de check INACTIVE, clé `token` pas
 * `access_token`, etc., déjà établi lors du port).
 */
class AdminAuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly UserService $userService,
        private readonly AuditLogService $auditLogService,
        private readonly OtpService $otpService,
    ) {}

    public function verifyOtp(string $email, string $code): array
    {
        $user = $this->otpService->verify($email, $code);

        return $this->buildTokenResponse($user->id);
    }

    public function loginWithGoogle(array $payload): array
    {
        $user = $this->userRepository->findByEmail($payload['email']);

        if (! $user) {
            $result = $this->userService->create([
                'name' => $payload['name'] ?: (trim(($payload['firstName'] ?? '').' '.($payload['lastName'] ?? '')) ?: $payload['email']),
                'email' => $payload['email'],
                'password' => 'google-'.Str::uuid(),
                'role' => UserRole::USER->value,
            ], null);

            $this->auditLogService->log('login', $result['user'], actor: $result['user']);

            return ['token' => $result['accessToken'], 'user' => new UserResource($result['user'])];
        }

        return $this->buildTokenResponse($user->id);
    }

    public function buildTokenResponse(string $userId): array
    {
        $user = $this->userRepository->find($userId);
        if (! $user) {
            abort(404, 'Utilisateur introuvable');
        }

        $token = auth('api')->login($user);
        $user->load('company');

        $this->auditLogService->log('login', $user, actor: $user);

        return ['token' => $token, 'user' => new UserResource($user)];
    }

    public function updateMyProfile(string $userId, array $data): UserResource
    {
        $user = $this->userRepository->find($userId);
        if (! $user) {
            abort(404, 'User not found');
        }

        return new UserResource($this->userService->update($user, $data, null));
    }

    public function uploadMyAvatar(string $userId, UploadedFile $avatar): array
    {
        $user = $this->userRepository->find($userId);
        if (! $user) {
            abort(404, 'User not found');
        }

        $updated = $this->userService->update($user, [], $avatar);

        return ['avatarUrl' => $updated->avatar];
    }
}
