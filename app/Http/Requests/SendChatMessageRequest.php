<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SendChatMessageRequest',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string'),
    ]
)]
class SendChatMessageRequest extends FormRequest
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
            // Un message peut n'être qu'un envoi de fichier, sans texte.
            'message' => ['nullable', 'required_without:files', 'string', 'max:4000'],
            'files' => ['sometimes', 'array', 'max:3'],
            'files.*' => ['file', 'max:5120', 'mimes:pdf,png,jpg,jpeg,webp,txt,docx'],
        ];
    }
}
