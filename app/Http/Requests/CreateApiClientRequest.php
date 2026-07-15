<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateApiClientRequest',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', description: 'Ex : "Sync SAP SuccessFactors"'),
        new OA\Property(property: 'isSandbox', type: 'boolean', default: true),
    ]
)]
class CreateApiClientRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'isSandbox' => ['nullable', 'boolean'],
        ];
    }
}
