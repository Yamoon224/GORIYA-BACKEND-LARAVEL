<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Vue allégée d'un utilisateur pour les listes GORIYA Connect (followers,
 * recommandations...) — contrairement à UserResource, n'expose jamais
 * l'email : ces listes sont visibles par d'autres utilisateurs, pas
 * seulement par le propriétaire ou un admin.
 */
#[OA\Schema(
    schema: 'ConnectUser',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'avatar', type: 'string', nullable: true),
        new OA\Property(property: 'companyName', type: 'string', nullable: true),
    ]
)]
class ConnectUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'companyName' => $this->whenLoaded('company', fn () => $this->company?->name),
        ];
    }
}
