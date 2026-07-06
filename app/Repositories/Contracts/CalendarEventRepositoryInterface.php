<?php

namespace App\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface CalendarEventRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator;

    /**
     * @return Collection<int, \App\Models\CalendarEvent>
     */
    public function findAllOrdered(): Collection;

    /**
     * @return Collection<int, \App\Models\CalendarEvent>
     */
    public function findBetween(Carbon $start, Carbon $end): Collection;

    /**
     * @param  array<int, string>  $statuses
     * @return Collection<int, \App\Models\CalendarEvent>
     */
    public function findUpcoming(array $statuses, int $limit): Collection;

    public function countByStatus(string $status): int;

    public function countUpcoming(Carbon $now, string $excludedStatus): int;

    public function countCompleted(Carbon $now, string $excludedStatus): int;
}
