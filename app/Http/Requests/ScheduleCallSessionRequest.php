<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ScheduleCallSessionRequest',
    required: ['title'],
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'scheduledAt', type: 'string', format: 'date-time', nullable: true, description: 'Absent/passé = démarrage immédiat'),
    ]
)]
class ScheduleCallSessionRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'scheduledAt' => ['nullable', 'date'],
        ];
    }
}
