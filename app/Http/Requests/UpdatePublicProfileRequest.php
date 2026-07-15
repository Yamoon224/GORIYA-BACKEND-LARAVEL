<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdatePublicProfileRequest',
    properties: [
        new OA\Property(property: 'slug', type: 'string', nullable: true, description: 'goriya.net/{slug} — un suffixe est ajouté automatiquement si déjà pris'),
        new OA\Property(property: 'theme', type: 'string', enum: ['DEFAULT', 'CREATIF', 'TECHNIQUE', 'COMMERCIAL', 'ACADEMIQUE'], nullable: true),
        new OA\Property(property: 'isPublic', type: 'boolean', nullable: true),
        new OA\Property(property: 'seoMeta', type: 'object', nullable: true),
    ]
)]
class UpdatePublicProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
            'theme' => ['sometimes', 'string', 'in:DEFAULT,CREATIF,TECHNIQUE,COMMERCIAL,ACADEMIQUE'],
            'isPublic' => ['sometimes', 'boolean'],
            'seoMeta' => ['sometimes', 'array'],
            'seoMeta.title' => ['sometimes', 'string', 'max:255'],
            'seoMeta.description' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
