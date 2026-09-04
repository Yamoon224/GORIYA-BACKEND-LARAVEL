<?php

namespace App\Http\Requests;

use App\Services\MessagingService;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SendMessageRequest',
    description: "Texte, pièce jointe, ou les deux — au moins l'un des deux.",
    properties: [
        new OA\Property(property: 'content', type: 'string', nullable: true),
        new OA\Property(property: 'attachment', type: 'string', format: 'binary', nullable: true),
    ]
)]
class SendMessageRequest extends FormRequest
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
            // `content` n'est plus exigé seul : un message peut n'être qu'un
            // fichier. `required_without` garde l'envoi vide impossible.
            'content' => ['required_without:attachment', 'nullable', 'string'],
            'attachment' => [
                'sometimes',
                'file',
                'max:'.(MessagingService::MAX_ATTACHMENT_BYTES / 1024),
            ],
        ];
    }
}
