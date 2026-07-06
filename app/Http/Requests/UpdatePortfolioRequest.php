<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdatePortfolioRequest',
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'skills', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'views', type: 'number', nullable: true),
        new OA\Property(property: 'downloads', type: 'number', nullable: true),
        new OA\Property(property: 'likes', type: 'number', nullable: true),
        new OA\Property(property: 'createdDate', type: 'string', format: 'date'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', nullable: true),
    ]
)]
class UpdatePortfolioRequest extends FormRequest
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
            'title' => ['sometimes', 'string'],
            'description' => ['sometimes', 'string'],
            'skills' => ['sometimes', 'array'],
            'skills.*' => ['string'],
            'views' => ['nullable', 'numeric'],
            'downloads' => ['nullable', 'numeric'],
            'likes' => ['nullable', 'numeric'],
            'createdDate' => ['sometimes', 'date'],
            'userId' => ['nullable', 'uuid'],
        ];
    }
}
