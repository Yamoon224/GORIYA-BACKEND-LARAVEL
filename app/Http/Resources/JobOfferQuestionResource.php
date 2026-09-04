<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'JobOfferQuestion',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['TEXT', 'TEXTAREA', 'NUMBER', 'BOOLEAN', 'SINGLE_CHOICE', 'MULTI_CHOICE']),
        new OA\Property(property: 'options', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'required', type: 'boolean'),
        new OA\Property(property: 'position', type: 'integer'),
    ]
)]
class JobOfferQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type->value,
            'options' => $this->options ?: null,
            'required' => (bool) $this->required,
            'position' => $this->position,
        ];
    }
}
