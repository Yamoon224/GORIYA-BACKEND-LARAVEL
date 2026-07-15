<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateChatThreadRequest',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', description: 'Premier message envoyé dans le nouveau fil'),
    ]
)]
class CreateChatThreadRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:4000'],
        ];
    }
}
