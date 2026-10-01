<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CvProfile',
    properties: [
        new OA\Property(property: 'profile', type: 'object', nullable: true),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class CvProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'profile' => $this->data,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
