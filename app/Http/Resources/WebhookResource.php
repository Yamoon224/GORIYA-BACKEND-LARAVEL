<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Webhook',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'url', type: 'string'),
        new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'isActive', type: 'boolean'),
        new OA\Property(property: 'secret', type: 'string', description: 'Visible uniquement à la création — pour signer/vérifier les payloads reçus'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class WebhookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => $this->events,
            'isActive' => $this->is_active,
            'secret' => $this->when($this->wasRecentlyCreated, $this->secret),
            'createdAt' => $this->created_at,
        ];
    }
}
