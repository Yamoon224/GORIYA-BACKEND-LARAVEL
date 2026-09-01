<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SaveCvRequest',
    properties: [
        new OA\Property(
            property: 'data',
            type: 'object',
            nullable: true,
            description: 'Contenu du formulaire ; seules les clés connues sont conservées',
            properties: [
                new OA\Property(property: 'nom', type: 'string'),
                new OA\Property(property: 'email', type: 'string'),
                new OA\Property(property: 'telephone', type: 'string'),
                new OA\Property(property: 'adresse', type: 'string'),
                new OA\Property(property: 'profil', type: 'string'),
                new OA\Property(property: 'competences', type: 'string'),
                new OA\Property(property: 'experience', type: 'string'),
                new OA\Property(property: 'formation', type: 'string'),
                new OA\Property(property: 'langues', type: 'string'),
            ],
        ),
        new OA\Property(property: 'step', type: 'integer', minimum: 0, maximum: 20),
    ]
)]
class SaveCvRequest extends FormRequest
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
            'data' => ['sometimes', 'nullable', 'array'],
            'data.nom' => ['sometimes', 'nullable', 'string', 'max:200'],
            'data.email' => ['sometimes', 'nullable', 'string', 'max:200'],
            'data.telephone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'data.adresse' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.profil' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'data.competences' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'data.experience' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'data.formation' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'data.langues' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'step' => ['sometimes', 'integer', 'min:0', 'max:20'],
        ];
    }
}
