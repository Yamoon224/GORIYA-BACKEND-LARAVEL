<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PostComment',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'postId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'author', ref: '#/components/schemas/ConnectUser', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class PostCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'postId' => $this->post_id,
            'content' => $this->content,
            'author' => $this->whenLoaded('user', fn () => (new ConnectUserResource($this->user))->resolve()),
            'createdAt' => $this->created_at,
        ];
    }
}
