<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/calendar-events/dto/calendar-event.vm.ts.
 */
#[OA\Schema(
    schema: 'CalendarEvent',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['ENTRETIEN', 'FORMATION', 'REUNION']),
        new OA\Property(property: 'startTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'endTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'participants', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['CONFIRMED', 'PENDING', 'CANCELLED']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class CalendarEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type->value,
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'participants' => $this->participants,
            'location' => $this->location,
            'status' => $this->status->value,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
