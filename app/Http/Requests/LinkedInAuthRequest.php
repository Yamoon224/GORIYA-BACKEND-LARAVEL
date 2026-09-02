<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LinkedInAuthRequest',
    required: ['code', 'redirectUri'],
    properties: [
        new OA\Property(property: 'code', type: 'string', description: "Authorization code renvoyé par LinkedIn sur l'URL de callback (paramètre `code`)"),
        new OA\Property(property: 'redirectUri', type: 'string', description: "URL de callback utilisée lors de l'autorisation — doit figurer dans LINKEDIN_REDIRECT_URIS"),
        new OA\Property(property: 'allowSignup', type: 'boolean', default: true, description: "Si false, refuse la connexion quand aucun compte n'existe déjà pour cet email (utilisé par l'espace entreprise)"),
    ]
)]
class LinkedInAuthRequest extends FormRequest
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
            'code' => ['required', 'string'],
            'redirectUri' => ['required', 'string', 'url'],
            'allowSignup' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Code LinkedIn manquant.',
            'redirectUri.required' => 'URL de retour LinkedIn manquante.',
        ];
    }
}
