<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Vue "propriétaire" du profil (gestion) — distincte du payload retourné par
 * PublicProfileController::show(), qui agrège aussi Portfolio/Pitch/posts.
 */
#[OA\Schema(
    schema: 'PublicProfile',
    properties: [
        new OA\Property(property: 'slug', type: 'string', nullable: true, description: 'URL personnalisée, null si le membre n\'en a pas choisi'),
        new OA\Property(property: 'ref', type: 'string', description: "Segment d'URL effectif : le slug, sinon l'uuid du membre"),
        new OA\Property(property: 'hasCustomUrl', type: 'boolean'),
        new OA\Property(property: 'theme', type: 'string', enum: ['DEFAULT', 'CREATIF', 'TECHNIQUE', 'COMMERCIAL', 'ACADEMIQUE']),
        new OA\Property(property: 'isPublic', type: 'boolean'),
        new OA\Property(property: 'seoMeta', type: 'object', nullable: true),
        new OA\Property(property: 'publicPath', type: 'string', example: '/p/awa-kone'),
        new OA\Property(property: 'publicUrl', type: 'string', example: 'goriya.net/p/awa-kone'),
    ]
)]
class PublicProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ref = $this->slug ?: $this->user_id;

        return [
            'slug' => $this->slug,
            'ref' => $ref,
            'hasCustomUrl' => (bool) $this->slug,
            'theme' => $this->theme,
            'isPublic' => $this->is_public,
            'seoMeta' => $this->seo_meta,
            'publicPath' => "/p/{$ref}",
            'publicUrl' => "goriya.net/p/{$ref}",
        ];
    }
}
