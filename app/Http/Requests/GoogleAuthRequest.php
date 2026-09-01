<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GoogleAuthRequest',
    required: ['credential'],
    properties: [
        new OA\Property(property: 'credential', type: 'string', description: "ID token JWT renvoyé par Google Identity Services (champ `credential` du callback)"),
        new OA\Property(property: 'allowSignup', type: 'boolean', default: true, description: "Si false, refuse la connexion quand aucun compte n'existe déjà pour cet email (utilisé par l'espace entreprise)"),
    ]
)]
class GoogleAuthRequest extends FormRequest
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
            'credential' => ['required', 'string'],
            'allowSignup' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credential.required' => 'Jeton Google manquant.',
        ];
    }
}
