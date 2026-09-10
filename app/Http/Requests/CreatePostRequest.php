<?php

namespace App\Http\Requests;

use App\Services\PostService;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreatePostRequest',
    properties: [
        new OA\Property(property: 'content', type: 'string', nullable: true, description: 'Obligatoire sans pièce jointe'),
        new OA\Property(property: 'communityId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(
            property: 'attachments',
            type: 'array',
            description: "Jusqu'à 9 images (JPG, PNG, WebP, GIF — 8 Mo) ou un PDF seul (10 Mo) — multipart/form-data",
            items: new OA\Items(type: 'string', format: 'binary')
        ),
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
            'content' => ['nullable', 'string', 'max:3000', 'required_without:attachments'],
            'communityId' => ['nullable', 'uuid', 'exists:communities,id'],
            'attachments' => ['sometimes', 'array', 'max:'.PostService::MAX_IMAGES],
            // Plafond large ici : la limite fine (image 8 Mo / PDF 10 Mo) dépend
            // du type et est appliquée par PostService.
            'attachments.*' => ['file', 'max:'.(PostService::MAX_DOCUMENT_BYTES / 1024)],
        ];
    }
}
