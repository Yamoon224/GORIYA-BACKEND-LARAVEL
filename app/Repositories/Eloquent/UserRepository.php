<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected function model(): string
    {
        return User::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = User::query()->with('company');

        if ($name = $filters['name'] ?? null) {
            $query->where('name', 'ilike', "%{$name}%");
        }
        if ($email = $filters['email'] ?? null) {
            $query->where('email', 'ilike', "%{$email}%");
        }
        if ($role = $filters['role'] ?? null) {
            $query->where('role', $role);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($registrationDate = $filters['registrationDate'] ?? null) {
            $query->whereDate('registration_date', $registrationDate);
        }
        if ($companyName = $filters['companyName'] ?? null) {
            $query->whereHas('company', fn ($q) => $q->where('name', 'ilike', "%{$companyName}%"));
        }
        if ($companyId = $filters['companyId'] ?? null) {
            $query->where('company_id', $companyId);
        }
        if ($companyStatus = $filters['companyStatus'] ?? null) {
            $query->whereHas('company', fn ($q) => $q->where('status', $companyStatus));
        }

        $query->orderByDesc('registration_date');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findByRole(string $role): Collection
    {
        return User::where('role', $role)->with('company')->get();
    }

    public function findByEmailWithPassword(string $email): ?User
    {
        return User::where('email', $email)
            ->select(['id', 'email', 'password', 'role', 'status', 'name'])
            ->first();
    }

    public function countByRole(string $role): int
    {
        return User::where('role', $role)->count();
    }

    public function countByRoleAndStatus(string $role, string $status): int
    {
        return User::where('role', $role)->where('status', $status)->count();
    }

    public function countByRoleCreatedBetween(string $role, Carbon $start, Carbon $end): int
    {
        return User::where('role', $role)->whereBetween('created_at', [$start, $end])->count();
    }
}
