<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Vue "propriétaire" du profil (gestion) — distincte du payload public
 * retourné par PublicProfileController::show(), qui agrège aussi
 * Portfolio/Pitch et n'expose jamais un profil non publié.
 */
#[OA\Schema(
    schema: 'PublicProfile',
    properties: [
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'theme', type: 'string', enum: ['DEFAULT', 'CREATIF', 'TECHNIQUE', 'COMMERCIAL', 'ACADEMIQUE']),
        new OA\Property(property: 'isPublic', type: 'boolean'),
        new OA\Property(property: 'seoMeta', type: 'object', nullable: true),
        new OA\Property(property: 'publicUrl', type: 'string'),
    ]
)]
class PublicProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'theme' => $this->theme,
            'isPublic' => $this->is_public,
            'seoMeta' => $this->seo_meta,
            'publicUrl' => "goriya.net/{$this->slug}",
        ];
    }
}
