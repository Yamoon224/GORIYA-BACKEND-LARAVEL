<?php

namespace App\Http\Requests;

use App\Enums\EventStatus;
use App\Enums\EventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateCalendarEventRequest',
    required: ['title', 'type', 'startTime', 'endTime', 'participants', 'status'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['ENTRETIEN', 'FORMATION', 'REUNION']),
        new OA\Property(property: 'startTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'endTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'participants', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['CONFIRMED', 'PENDING', 'CANCELLED']),
    ]
)]
class CreateCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            'type' => ['required', Rule::enum(EventType::class)],
            'startTime' => ['required', 'date'],
            'endTime' => ['required', 'date'],
            'participants' => ['required', 'array'],
            'participants.*' => ['string'],
            'location' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(EventStatus::class)],
        ];
    }
}
