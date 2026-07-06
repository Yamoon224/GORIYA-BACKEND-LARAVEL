<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator;

    public function findByEmail(string $email): ?User;

    /**
     * @return Collection<int, User>
     */
    public function findByRole(string $role): Collection;

    /**
     * Sélection explicite incluant le hash de mot de passe (colonne
     * normalement absente des lectures courantes) — réservée à l'auth.
     */
    public function findByEmailWithPassword(string $email): ?User;

    public function countByRole(string $role): int;

    public function countByRoleAndStatus(string $role, string $status): int;

    public function countByRoleCreatedBetween(string $role, Carbon $start, Carbon $end): int;
}
