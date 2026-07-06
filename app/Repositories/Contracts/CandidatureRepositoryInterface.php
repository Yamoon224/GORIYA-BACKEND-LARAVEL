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
}
