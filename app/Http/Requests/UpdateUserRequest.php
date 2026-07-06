<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateUserRequest',
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
class UpdateUserRequest extends FormRequest
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
            'name' => ['sometimes', 'string'],
            'email' => ['sometimes', 'email'],
            'password' => ['sometimes', 'string', 'min:6'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'companyId' => ['nullable', 'uuid'],
            'avatar' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/jpg,image/webp'],
        ];
    }
}
