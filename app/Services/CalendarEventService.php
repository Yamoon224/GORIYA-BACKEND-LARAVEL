<?php

namespace App\Services;

use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\CalendarEvent;
use App\Repositories\Contracts\CalendarEventRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

/**
 * Mirroir de backend/src/calendar-events/calendar-events.service.ts.
 */
class CalendarEventService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    public function __construct(private readonly CalendarEventRepositoryInterface $calendarEventRepository) {}

    public function create(array $data): CalendarEvent
    {
        try {
            return $this->calendarEventRepository->create([
                'title' => $data['title'],
                'type' => $data['type'],
                'start_time' => $data['startTime'],
                'end_time' => $data['endTime'],
                'participants' => $data['participants'],
                'location' => $data['location'] ?? null,
                'status' => $data['status'],
            ]);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }
    }

    public function update(CalendarEvent $event, array $data): CalendarEvent
    {
        $mapped = $this->mapFields($data, [
            'title' => 'title',
            'type' => 'type',
            'startTime' => 'start_time',
            'endTime' => 'end_time',
            'participants' => 'participants',
            'location' => 'location',
            'status' => 'status',
        ]);

        try {
            $this->calendarEventRepository->update($event, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $event;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->calendarEventRepository->paginate($page, $limit, $filters);
    }

    public function remove(CalendarEvent $event): void
    {
        $this->calendarEventRepository->delete($event);
    }
}
