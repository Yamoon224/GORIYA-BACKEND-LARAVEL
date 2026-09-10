<?php

namespace App\Http\Requests;

use App\Models\Portfolio;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreatePortfolioRequest',
    required: ['title'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'skills', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'views', type: 'number', nullable: true),
        new OA\Property(property: 'downloads', type: 'number', nullable: true),
        new OA\Property(property: 'likes', type: 'number', nullable: true),
        new OA\Property(property: 'createdDate', type: 'string', format: 'date', nullable: true, description: "Défaut : aujourd'hui"),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', nullable: true, description: "Ignoré sauf pour un admin : le portfolio appartient à l'utilisateur connecté"),
        new OA\Property(property: 'theme', type: 'string', enum: Portfolio::THEMES),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'PUBLISHED']),
        new OA\Property(property: 'photo', type: 'string', nullable: true, description: 'Chemin renvoyé par POST /portfolios/photo'),
        new OA\Property(
            property: 'details',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'fullName', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', nullable: true),
                new OA\Property(property: 'phone', type: 'string', nullable: true),
                new OA\Property(property: 'location', type: 'string', nullable: true),
                new OA\Property(property: 'skillLevels', type: 'object', description: 'Nom de compétence → niveau 0-100'),
                new OA\Property(property: 'projects', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', nullable: true),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'status', type: 'string', enum: ['EN_COURS', 'TERMINE']),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'previewUrl', type: 'string', nullable: true),
                    new OA\Property(property: 'codeUrl', type: 'string', nullable: true),
                ])),
                new OA\Property(property: 'links', type: 'object', properties: [
                    new OA\Property(property: 'linkedin', type: 'string', nullable: true),
                    new OA\Property(property: 'github', type: 'string', nullable: true),
                    new OA\Property(property: 'twitter', type: 'string', nullable: true),
                    new OA\Property(property: 'website', type: 'string', nullable: true),
                ]),
            ]
        ),
    ]
)]
class CreatePortfolioRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'skills' => ['sometimes', 'array'],
            'skills.*' => ['string', 'max:100'],
            'views' => ['nullable', 'numeric'],
            'downloads' => ['nullable', 'numeric'],
            'likes' => ['nullable', 'numeric'],
            'createdDate' => ['nullable', 'date'],
            'userId' => ['nullable', 'uuid'],
            ...self::editorRules(),
        ];
    }

    /**
     * Champs de l'éditeur complet, partagés avec UpdatePortfolioRequest.
     * `array:` liste les clés admises : `details` est stocké tel quel en JSON,
     * il ne doit rien accepter d'autre que ce que l'éditeur sait afficher.
     *
     * @return array<string, mixed>
     */
    public static function editorRules(): array
    {
        return [
            'theme' => ['sometimes', 'string', 'in:'.implode(',', Portfolio::THEMES)],
            'status' => ['sometimes', 'string', 'in:'.Portfolio::STATUS_DRAFT.','.Portfolio::STATUS_PUBLISHED],
            'photo' => ['sometimes', 'nullable', 'string', 'regex:#^/portfolios/[0-9a-f\-]{36}\.(jpg|png|webp)$#'],
            'details' => ['sometimes', 'nullable', 'array:fullName,email,phone,location,skillLevels,projects,links'],
            'details.fullName' => ['nullable', 'string', 'max:255'],
            'details.email' => ['nullable', 'email', 'max:255'],
            'details.phone' => ['nullable', 'string', 'max:50'],
            'details.location' => ['nullable', 'string', 'max:255'],
            'details.skillLevels' => ['nullable', 'array'],
            'details.skillLevels.*' => ['integer', 'min:0', 'max:100'],
            'details.projects' => ['nullable', 'array', 'max:20'],
            'details.projects.*' => ['array:id,title,status,description,previewUrl,codeUrl'],
            'details.projects.*.id' => ['nullable', 'string', 'max:64'],
            'details.projects.*.title' => ['required', 'string', 'max:150'],
            'details.projects.*.status' => ['nullable', 'string', 'in:EN_COURS,TERMINE'],
            'details.projects.*.description' => ['nullable', 'string', 'max:2000'],
            'details.projects.*.previewUrl' => ['nullable', 'url', 'max:500'],
            'details.projects.*.codeUrl' => ['nullable', 'url', 'max:500'],
            'details.links' => ['nullable', 'array:linkedin,github,twitter,website'],
            'details.links.*' => ['nullable', 'url', 'max:500'],
        ];
    }
}
