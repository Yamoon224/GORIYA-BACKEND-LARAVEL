<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ConsumeAnonymousUsageRequest',
    required: ['deviceId', 'featureKey'],
    properties: [
        new OA\Property(property: 'deviceId', type: 'string'),
        new OA\Property(property: 'featureKey', type: 'string'),
    ]
)]
class ConsumeAnonymousUsageRequest extends FormRequest
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
            'deviceId' => ['required', 'string'],
            'featureKey' => ['required', 'string'],
        ];
    }
}
