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
        new OA\Property(property: 'invitees', type: 'array', items: new OA\Items(type: 'string', format: 'email'), nullable: true, description: 'Adresses à inviter par email (20 au plus).'),
        new OA\Property(property: 'description', type: 'string', nullable: true, description: "Message de l'organisateur, repris dans l'invitation."),
        new OA\Property(property: 'guestIds', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true, description: 'Invités qui verront la session dans leur liste — ex. l\'autre participant d\'une conversation.'),
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
            'guestIds' => ['nullable', 'array'],
            'guestIds.*' => ['uuid', 'exists:users,id'],
            // Personnes conviées par email (membres ou non) : chacune reçoit l'invitation.
            'invitees' => ['nullable', 'array', 'max:20'],
            'invitees.*' => ['email:filter', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
