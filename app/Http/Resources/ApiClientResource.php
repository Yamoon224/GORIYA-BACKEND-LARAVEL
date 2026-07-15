<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApiClient',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'isSandbox', type: 'boolean'),
        new OA\Property(property: 'isActive', type: 'boolean'),
        new OA\Property(property: 'rateLimitPerMinute', type: 'integer'),
        new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class ApiClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isSandbox' => $this->is_sandbox,
            'isActive' => $this->is_active,
            'rateLimitPerMinute' => $this->rate_limit_per_minute,
            'lastUsedAt' => $this->last_used_at,
            'createdAt' => $this->created_at,
        ];
    }
}
