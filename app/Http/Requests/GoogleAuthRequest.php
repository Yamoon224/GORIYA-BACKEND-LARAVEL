<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GoogleAuthRequest',
    required: ['googleId', 'email', 'name'],
    properties: [
        new OA\Property(property: 'googleId', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'firstName', type: 'string', nullable: true),
        new OA\Property(property: 'lastName', type: 'string', nullable: true),
        new OA\Property(property: 'picture', type: 'string', nullable: true),
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
            'googleId' => ['required', 'string'],
            'email' => ['required', 'email'],
            'name' => ['required', 'string'],
            'firstName' => ['nullable', 'string'],
            'lastName' => ['nullable', 'string'],
            'picture' => ['nullable', 'string'],
        ];
    }
}
