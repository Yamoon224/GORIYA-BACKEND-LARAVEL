<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/users/dto/user.vm.ts. Le contrôleur doit toujours
 * charger la relation `company` (findAll/findOne/paginate) pour que cette clé
 * soit fiable — pas de whenLoaded() conditionnel ici.
 */
#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'role', type: 'string', enum: ['ADMIN', 'USER', 'ENTREPRISE']),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE']),
        new OA\Property(property: 'avatar', type: 'string', nullable: true),
        new OA\Property(property: 'registrationDate', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'company',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
            ]
        ),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'avatar' => $this->avatar,
            'registrationDate' => $this->registration_date,
            'company' => $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ] : null,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
