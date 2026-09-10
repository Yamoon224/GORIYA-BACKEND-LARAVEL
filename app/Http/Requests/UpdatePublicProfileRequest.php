<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdatePublicProfileRequest',
    properties: [
        new OA\Property(property: 'slug', type: 'string', nullable: true, description: 'URL personnalisée goriya.net/p/{slug} — null ou vide pour revenir à l\'uuid ; 422 si déjà prise'),
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
            // Minuscules, chiffres et tirets isolés : « awa-kone », pas « -awa » ni « awa--kone ».
            'slug' => ['sometimes', 'nullable', 'string', 'min:3', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'theme' => ['sometimes', 'string', 'in:DEFAULT,CREATIF,TECHNIQUE,COMMERCIAL,ACADEMIQUE'],
            'isPublic' => ['sometimes', 'boolean'],
            'seoMeta' => ['sometimes', 'nullable', 'array:title,description'],
            'seoMeta.title' => ['nullable', 'string', 'max:255'],
            'seoMeta.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
