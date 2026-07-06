<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateUserRequest',
    required: ['name', 'email', 'password'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 6),
        new OA\Property(property: 'role', type: 'string', enum: ['ADMIN', 'USER', 'ENTREPRISE'], nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
        new OA\Property(property: 'companyId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'avatar', type: 'string', format: 'binary', nullable: true, description: 'image/png, jpeg, jpg ou webp'),
    ]
)]
class CreateUserRequest extends FormRequest
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
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            // L'existence de la company est vérifiée dans le contrôleur (404),
            // pas ici — parité avec le service NestJS.
            'companyId' => ['nullable', 'uuid'],
            'avatar' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/jpg,image/webp'],
        ];
    }
}
