<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateCheckoutRequest',
    required: ['userId', 'planId', 'successUrl', 'errorUrl'],
    properties: [
        new OA\Property(property: 'userId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'planId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'successUrl', type: 'string'),
        new OA\Property(property: 'errorUrl', type: 'string'),
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
            'successUrl' => ['required', 'string'],
            'errorUrl' => ['required', 'string'],
        ];
    }
}
