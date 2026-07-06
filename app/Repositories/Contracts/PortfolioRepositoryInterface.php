<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface PortfolioRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator;

    /**
     * @return Collection<int, \App\Models\Portfolio>
     */
    public function findFeatured(int $limit): Collection;
}
