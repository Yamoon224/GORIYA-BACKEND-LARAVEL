<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreatePresentationRequest',
    required: ['title', 'type', 'brief'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['SLIDES', 'SCHEMA']),
        new OA\Property(property: 'brief', type: 'string'),
    ]
)]
class CreatePresentationRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:SLIDES,SCHEMA'],
            'brief' => ['required', 'string', 'max:3000'],
        ];
    }
}
