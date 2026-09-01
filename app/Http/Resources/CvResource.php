<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Cv',
    properties: [
        new OA\Property(property: 'data', type: 'object', nullable: true),
        new OA\Property(property: 'step', type: 'integer'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class CvResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->data,
            'step' => (int) ($this->step ?? 0),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
