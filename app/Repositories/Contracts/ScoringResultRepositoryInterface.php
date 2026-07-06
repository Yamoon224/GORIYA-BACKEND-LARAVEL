<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ScoringResultRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator;

    /**
     * @return Collection<int, \App\Models\ScoringResult>
     */
    public function findRecent(int $limit): Collection;

    /**
     * @return Collection<int, \App\Models\ScoringResult>
     */
    public function findAllOrderedByAnalysisDate(): Collection;
}
