<?php

namespace App\Repositories\Eloquent;

use App\Http\Concerns\BuildsPgArrayLiterals;
use App\Models\CalendarEvent;
use App\Repositories\Contracts\CalendarEventRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CalendarEventRepository extends BaseRepository implements CalendarEventRepositoryInterface
{
    use BuildsPgArrayLiterals;

    protected function model(): string
    {
        return CalendarEvent::class;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = CalendarEvent::query();

        if ($title = $filters['title'] ?? null) {
            $query->whereILike('title', $title);
        }
        if ($type = $filters['type'] ?? null) {
            $query->where('type', $type);
        }
        if ($startTime = $filters['startTime'] ?? null) {
            $query->whereDate('start_time', $startTime);
        }
        if ($endTime = $filters['endTime'] ?? null) {
            $query->whereDate('end_time', $endTime);
        }

        $participants = $filters['participants'] ?? null;
        if ($participants) {
            $participants = is_array($participants) ? $participants : [$participants];
            $query->whereRaw('participants && ?::text[]', [$this->toPgArrayLiteral($participants)]);
        }

        if ($location = $filters['location'] ?? null) {
            $query->whereILike('location', $location);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        $query->orderBy('start_time');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findAllOrdered(): Collection
    {
        return CalendarEvent::orderBy('start_time')->get();
    }

    public function findBetween(Carbon $start, Carbon $end): Collection
    {
        return CalendarEvent::whereBetween('start_time', [$start, $end])->orderBy('start_time')->get();
    }

    public function findUpcoming(array $statuses, int $limit): Collection
    {
        return CalendarEvent::whereIn('status', $statuses)
            ->orderBy('start_time')
            ->take($limit)
            ->get();
    }

    public function countByStatus(string $status): int
    {
        return CalendarEvent::where('status', $status)->count();
    }

    public function countUpcoming(Carbon $now, string $excludedStatus): int
    {
        return CalendarEvent::where('start_time', '>=', $now)->where('status', '!=', $excludedStatus)->count();
    }

    public function countCompleted(Carbon $now, string $excludedStatus): int
    {
        return CalendarEvent::where('end_time', '<', $now)->where('status', '!=', $excludedStatus)->count();
    }
}
