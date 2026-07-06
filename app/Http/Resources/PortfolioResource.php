<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/portfolios/dto/portfolio.vm.ts.
 */
#[OA\Schema(
    schema: 'Portfolio',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'skills', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'views', type: 'integer'),
        new OA\Property(property: 'downloads', type: 'integer'),
        new OA\Property(property: 'likes', type: 'integer'),
        new OA\Property(property: 'createdDate', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'user',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
            ]
        ),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class PortfolioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'skills' => $this->skills,
            'views' => $this->views,
            'downloads' => $this->downloads,
            'likes' => $this->likes,
            'createdDate' => $this->created_date,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
