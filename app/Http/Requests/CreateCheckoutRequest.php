<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateCheckoutRequest',
    required: ['userId', 'planId'],
    properties: [
        new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'planId', type: 'string', format: 'uuid'),
    ]
)]
class CreateCheckoutRequest extends FormRequest
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
            'userId' => ['required', 'uuid'],
            'planId' => ['required', 'uuid'],
        ];
    }
}
