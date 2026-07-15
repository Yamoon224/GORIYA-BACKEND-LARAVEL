<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ExternalUpdateCandidatureStatusRequest',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['EN_ATTENTE', 'EN_COURS', 'APPROUVEE', 'REJETEE']),
    ]
)]
class ExternalUpdateCandidatureStatusRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:EN_ATTENTE,EN_COURS,APPROUVEE,REJETEE'],
        ];
    }
}
