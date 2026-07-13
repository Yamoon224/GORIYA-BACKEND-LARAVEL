<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreatePitchRequest',
    required: ['type'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['EMPLOI', 'CONCOURS', 'APPEL_PROJET', 'STARTUP']),
        new OA\Property(property: 'jobOfferId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'content', type: 'string', nullable: true, description: 'Script fourni manuellement — sinon généré par IA'),
    ]
)]
class CreatePitchRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:EMPLOI,CONCOURS,APPEL_PROJET,STARTUP'],
            'jobOfferId' => ['nullable', 'uuid', 'exists:job_offers,id'],
            'content' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
