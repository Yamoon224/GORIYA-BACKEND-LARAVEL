<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ChatMessage',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'role', type: 'string', enum: ['USER', 'ASSISTANT']),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class ChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'createdAt' => $this->created_at,
        ];
    }
}
