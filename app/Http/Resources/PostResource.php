<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Post',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'author', ref: '#/components/schemas/ConnectUser', nullable: true),
        new OA\Property(property: 'communityId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'likesCount', type: 'integer'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'author' => $this->whenLoaded('user', fn () => new ConnectUserResource($this->user)),
            'communityId' => $this->community_id,
            'likesCount' => $this->whenCounted('likes'),
            'createdAt' => $this->created_at,
        ];
    }
}
