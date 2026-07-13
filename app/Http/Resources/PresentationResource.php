<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Presentation',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['SLIDES', 'SCHEMA']),
        new OA\Property(property: 'brief', type: 'string'),
        new OA\Property(property: 'content', type: 'object', description: 'slides[] pour SLIDES, nodes[]/edges[] pour SCHEMA'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class PresentationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'brief' => $this->brief,
            'content' => $this->content,
            'createdAt' => $this->created_at,
        ];
    }
}
