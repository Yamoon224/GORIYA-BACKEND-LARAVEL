<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UserResume',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'url', type: 'string', nullable: true),
        new OA\Property(property: 'mimeType', type: 'string', nullable: true),
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'isDefault', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class UserResumeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => MediaUrl::resolve($this->path),
            'mimeType' => $this->mime_type,
            'size' => $this->size,
            'isDefault' => (bool) $this->is_default,
            'createdAt' => $this->created_at,
        ];
    }
}
