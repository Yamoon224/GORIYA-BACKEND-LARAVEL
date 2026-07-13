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
        new OA\Property(property: 'gateway', type: 'string', enum: ['kkiapay', 'wave', 'stripe'], description: 'Défaut : services.payment.default_gateway'),
        new OA\Property(property: 'currency', type: 'string', example: 'XOF'),
        new OA\Property(property: 'successUrl', type: 'string', description: 'Requis pour wave/stripe (session hébergée)'),
        new OA\Property(property: 'errorUrl', type: 'string', description: 'Requis pour wave/stripe (session hébergée)'),
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
            'gateway' => ['nullable', 'string', 'in:kkiapay,wave,stripe'],
            'currency' => ['nullable', 'string', 'size:3'],
            'successUrl' => ['nullable', 'url'],
            'errorUrl' => ['nullable', 'url'],
        ];
    }
}
