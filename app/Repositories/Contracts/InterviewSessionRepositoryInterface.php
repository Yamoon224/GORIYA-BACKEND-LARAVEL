<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface InterviewSessionRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator;

    /**
     * @param  array<int, string>  $statuses
     * @return Collection<int, \App\Models\InterviewSession>
     */
    public function findByStatus(array $statuses): Collection;

    /**
     * @return Collection<int, \App\Models\InterviewSession>
     */
    public function findCompletedOrderedByStartTime(): Collection;
}
