<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateMyProfileRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string', nullable: true, description: 'Titre professionnel'),
        new OA\Property(property: 'location', type: 'string', nullable: true, description: 'Ville / localisation'),
        new OA\Property(property: 'bio', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
    ]
)]
class UpdateMyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Tous les champs sont facultatifs : l'écran de complétion de profil peut
     * être passé, et n'envoyer qu'une partie des informations.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
