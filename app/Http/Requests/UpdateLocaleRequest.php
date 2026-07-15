<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateLocaleRequest',
    required: ['locale'],
    properties: [
        new OA\Property(property: 'locale', type: 'string', enum: ['fr', 'en', 'pt', 'ar']),
    ]
)]
class UpdateLocaleRequest extends FormRequest
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
            'locale' => ['required', 'string', 'in:fr,en,pt,ar'],
        ];
    }
}
