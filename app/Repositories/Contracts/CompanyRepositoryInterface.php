<?php

namespace App\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CompanyRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator;

    public function countByStatus(string $status): int;

    public function countCreatedBetween(Carbon $start, Carbon $end): int;
}
