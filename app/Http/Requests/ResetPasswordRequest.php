<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResetPasswordRequest',
    required: ['email', 'code', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'code', type: 'string', description: 'Code à 6 chiffres reçu par email', example: '123456'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 6),
    ]
)]
class ResetPasswordRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            // Même minimum qu'à l'inscription (CreateUserRequest) : la
            // réinitialisation ne doit pas être une porte dérobée vers un
            // mot de passe plus faible que celui autorisé à la création.
            'password' => ['required', 'string', 'min:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => "L'adresse e-mail est requise.",
            'email.email' => "L'adresse e-mail n'est pas valide.",
            'code.required' => 'Le code reçu par e-mail est requis.',
            'password.required' => 'Le nouveau mot de passe est requis.',
            'password.min' => 'Le mot de passe doit contenir au moins 6 caractères.',
        ];
    }
}
