<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResearchQuery',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'companyName', type: 'string'),
        new OA\Property(
            property: 'result',
            type: 'object',
            properties: [
                new OA\Property(property: 'historique', type: 'string'),
                new OA\Property(property: 'valeurs', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'culture', type: 'string'),
                new OA\Property(property: 'actualites', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'synthese', type: 'string'),
                new OA\Property(property: 'recommandations', type: 'array', items: new OA\Items(type: 'string')),
            ]
        ),
        new OA\Property(property: 'isFavorite', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class ResearchQueryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'companyName' => $this->company_name,
            'result' => $this->result,
            'isFavorite' => $this->is_favorite,
            'createdAt' => $this->created_at,
        ];
    }
}
