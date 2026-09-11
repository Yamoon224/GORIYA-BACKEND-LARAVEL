<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CallSession',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'hostId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'isHost', type: 'boolean', description: "L'utilisateur connecté est l'hôte (sinon invité)"),
        new OA\Property(property: 'hostName', type: 'string', nullable: true, description: 'Liste des sessions uniquement'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'roomSlug', type: 'string'),
        new OA\Property(property: 'scheduledAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['SCHEDULED', 'ACTIVE', 'ENDED']),
        new OA\Property(property: 'recordingUrl', type: 'string', nullable: true),
        new OA\Property(property: 'endedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class CallSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hostId' => $this->host_id,
            // Un invité (candidat convoqué) rejoint la session mais ne la clôture pas.
            'isHost' => $request->user()?->id === $this->host_id,
            'hostName' => $this->whenLoaded('host', fn () => $this->host?->name),
            'title' => $this->title,
            'roomSlug' => $this->room_slug,
            'scheduledAt' => $this->scheduled_at,
            'status' => $this->status,
            'recordingUrl' => $this->recording_url,
            'endedAt' => $this->ended_at,
            'createdAt' => $this->created_at,
        ];
    }
}
