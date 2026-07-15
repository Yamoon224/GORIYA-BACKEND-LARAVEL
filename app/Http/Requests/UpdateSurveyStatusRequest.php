<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateSurveyStatusRequest',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'ACTIVE', 'CLOSED']),
    ]
)]
class UpdateSurveyStatusRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:DRAFT,ACTIVE,CLOSED'],
        ];
    }
}
