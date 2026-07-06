<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\User;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mirroir de backend/src/users/users.service.ts. Utilisé par les
 * contrôleurs Api (auto-inscription, validée) et Admin (création côté
 * admin, non validée par un Form Request — parité avec le body
 * `Record<string, unknown>` non typé côté NestJS).
 */
class UserService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    private const ALLOWED_AVATAR_TYPES = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly CompanyRepositoryInterface $companyRepository,
    ) {}

    /**
     * @return array{user: User, accessToken: string}
     */
    public function create(array $data, ?UploadedFile $avatar, bool $allowAdminRole = false): array
    {
        if (! $allowAdminRole && ($data['role'] ?? null) === UserRole::ADMIN->value) {
            abort(400, 'Impossible de créer un compte administrateur par cette voie');
        }

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ];

        if (array_key_exists('role', $data)) {
            $payload['role'] = $data['role'];
        }
        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
        }

        if ($avatar) {
            $payload['avatar'] = $this->storeAvatar($avatar);
        }

        if (! empty($data['companyId'])) {
            $role = $data['role'] ?? UserRole::USER->value;
            if ($role !== UserRole::ENTERPRISE->value) {
                abort(400, 'Only enterprise users can have a company');
            }

            $company = $this->companyRepository->find($data['companyId']);
            if (! $company) {
                abort(404, 'Company not found');
            }

            $payload['company_id'] = $company->id;
        }

        try {
            $user = $this->userRepository->create($payload);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, ['email' => 'Cette adresse email est déjà utilisée']);
        }

        // Refetch before signing: fields left out of $payload (role/status) only
        // get their DB default applied on the row, not on the in-memory model,
        // so signing $user directly here would bake a null role into the JWT.
        $fullUser = $this->userRepository->findOrFail($user->id);
        $fullUser->load('company');
        $accessToken = auth('api')->login($fullUser);

        return ['user' => $fullUser, 'accessToken' => $accessToken];
    }

    public function update(User $user, array $data, ?UploadedFile $avatar): User
    {
        $mapped = [];

        if ($avatar) {
            if ($user->avatar) {
                $this->deleteAvatar($user->avatar);
            }
            $mapped['avatar'] = $this->storeAvatar($avatar);
        }

        if (! empty($data['companyId'])) {
            $role = $data['role'] ?? $user->role->value;
            if ($role !== UserRole::ENTERPRISE->value) {
                abort(400, 'Only enterprise users can have a company');
            }

            $company = $this->companyRepository->find($data['companyId']);
            if (! $company) {
                abort(404, 'Company not found');
            }

            $mapped['company_id'] = $company->id;
        }

        $mapped += $this->mapFields($data, [
            'name' => 'name',
            'email' => 'email',
            'password' => 'password',
            'role' => 'role',
            'status' => 'status',
        ]);

        try {
            $this->userRepository->update($user, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, ['email' => 'Cette adresse email est déjà utilisée']);
        }

        return $user->fresh('company');
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->userRepository->paginate($page, $limit, $filters);
    }

    public function remove(User $user): void
    {
        if ($user->avatar) {
            $this->deleteAvatar($user->avatar);
        }

        $this->userRepository->delete($user);
    }

    private function storeAvatar(UploadedFile $file): string
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_AVATAR_TYPES, true)) {
            abort(400, 'Unsupported file type');
        }

        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        Storage::disk('public')->putFileAs('avatars', $file, $filename);

        return "/avatars/{$filename}";
    }

    private function deleteAvatar(string $path): void
    {
        Storage::disk('public')->delete('avatars/'.basename($path));
    }
}
