<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CandidatureRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator;

    public function countByStatus(string $status): int;

    public function existsForUserAndJob(string $userId, string $jobOfferId): bool;

    /**
     * Identifiants des offres auxquelles l'utilisateur a déjà postulé.
     *
     * @return list<string>
     */
    public function appliedJobIds(string $userId): array;
}
