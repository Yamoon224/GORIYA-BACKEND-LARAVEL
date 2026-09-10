<?php

namespace App\Http\Resources;

use App\Models\PostAttachment;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Post',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'content', type: 'string', nullable: true),
        new OA\Property(property: 'author', ref: '#/components/schemas/ConnectUser', nullable: true),
        new OA\Property(property: 'communityId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(
            property: 'attachments',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'type', type: 'string', enum: ['IMAGE', 'DOCUMENT']),
                new OA\Property(property: 'url', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'mimeType', type: 'string', nullable: true),
                new OA\Property(property: 'size', type: 'integer'),
            ])
        ),
        new OA\Property(property: 'likesCount', type: 'integer'),
        new OA\Property(property: 'commentsCount', type: 'integer'),
        new OA\Property(property: 'repostsCount', type: 'integer'),
        new OA\Property(property: 'likedByMe', type: 'boolean'),
        new OA\Property(property: 'repostedByMe', type: 'boolean'),
        new OA\Property(property: 'repostOf', type: 'object', nullable: true, description: 'Post republié (même forme, sans compteurs)'),
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
            'author' => $this->whenLoaded('user', fn () => (new ConnectUserResource($this->user))->resolve($request)),
            'communityId' => $this->community_id,
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->map(fn (PostAttachment $attachment) => [
                    'id' => $attachment->id,
                    'type' => $attachment->type,
                    'url' => MediaUrl::resolve($attachment->path),
                    'name' => $attachment->name,
                    'mimeType' => $attachment->mime_type,
                    'size' => $attachment->size,
                ])
                ->values()
                ->all()),
            'likesCount' => $this->whenCounted('likes'),
            'commentsCount' => $this->whenCounted('comments'),
            'repostsCount' => $this->whenCounted('reposts'),
            'likedByMe' => (bool) ($this->liked_by_me ?? false),
            'repostedByMe' => (bool) ($this->reposted_by_me ?? false),
            // Le post d'origine n'est rendu que s'il a été chargé : jamais de
            // requête paresseuse depuis une ressource (N+1 sur tout le fil).
            'repostOf' => $this->when(
                $this->repost_of_id !== null && $this->relationLoaded('repostOf'),
                fn () => $this->repostOf ? (new self($this->repostOf))->resolve($request) : null,
            ),
            'createdAt' => $this->created_at,
        ];
    }
}
