<?php

namespace App\Http\Requests;

use App\Enums\ArticleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateArticleRequest',
    required: ['title', 'content'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'slug', type: 'string', nullable: true, description: 'Généré depuis le titre si absent'),
        new OA\Property(property: 'excerpt', type: 'string', nullable: true),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'authorName', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'PUBLISHED'], nullable: true),
        new OA\Property(property: 'coverImage', type: 'string', format: 'binary', nullable: true),
    ]
)]
class CreateArticleRequest extends FormRequest
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
            'title' => ['required', 'string'],
            'slug' => ['nullable', 'string'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'authorName' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(ArticleStatus::class)],
            'coverImage' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/jpg,image/webp'],
        ];
    }
}
