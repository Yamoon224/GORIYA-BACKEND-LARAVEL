<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateEnrollmentProgressRequest',
    required: ['progress'],
    properties: [
        new OA\Property(property: 'progress', type: 'integer', minimum: 0, maximum: 100),
    ]
)]
class UpdateEnrollmentProgressRequest extends FormRequest
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
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
