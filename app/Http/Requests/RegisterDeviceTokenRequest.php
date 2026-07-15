<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegisterDeviceTokenRequest',
    required: ['token', 'platform'],
    properties: [
        new OA\Property(property: 'token', type: 'string'),
        new OA\Property(property: 'platform', type: 'string', enum: ['ANDROID', 'IOS', 'WEB']),
    ]
)]
class RegisterDeviceTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:ANDROID,IOS,WEB'],
        ];
    }
}
