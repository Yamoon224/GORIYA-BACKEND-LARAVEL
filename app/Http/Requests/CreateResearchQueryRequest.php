<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateResearchQueryRequest',
    required: ['companyName'],
    properties: [
        new OA\Property(property: 'companyName', type: 'string'),
    ]
)]
class CreateResearchQueryRequest extends FormRequest
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
            'companyName' => ['required', 'string', 'max:255'],
        ];
    }
}
