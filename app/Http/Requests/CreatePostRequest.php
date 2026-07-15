<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreatePostRequest',
    required: ['content'],
    properties: [
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'communityId', type: 'string', format: 'uuid', nullable: true),
    ]
)]
class CreatePostRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:3000'],
            'communityId' => ['nullable', 'uuid', 'exists:communities,id'],
        ];
    }
}
