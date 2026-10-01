<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SaveCvProfileRequest',
    properties: [
        new OA\Property(
            property: 'profile',
            type: 'object',
            nullable: true,
            description: 'Profil validé par le candidat ; seules les clés connues sont conservées',
            properties: [
                new OA\Property(property: 'firstName', type: 'string'),
                new OA\Property(property: 'fullName', type: 'string'),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'phone', type: 'string', description: 'Numéro avec indicatif'),
                new OA\Property(property: 'email', type: 'string'),
                new OA\Property(property: 'birthDate', type: 'string', format: 'date'),
                new OA\Property(property: 'gender', type: 'string', enum: ['', 'Homme', 'Femme']),
                new OA\Property(property: 'desiredJob', type: 'string'),
                new OA\Property(
                    property: 'experiences',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'position', type: 'string'),
                        new OA\Property(property: 'company', type: 'string'),
                        new OA\Property(property: 'location', type: 'string'),
                        new OA\Property(property: 'date', type: 'string'),
                        new OA\Property(property: 'skills', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'missions', type: 'array', items: new OA\Items(type: 'string')),
                    ], type: 'object'),
                ),
                new OA\Property(property: 'skills', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(
                    property: 'educations',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'level', type: 'string'),
                        new OA\Property(property: 'school', type: 'string'),
                        new OA\Property(property: 'date', type: 'string'),
                        new OA\Property(property: 'skills', type: 'array', items: new OA\Items(type: 'string')),
                    ], type: 'object'),
                ),
                new OA\Property(property: 'hobbies', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'linkedin', type: 'string'),
                new OA\Property(property: 'website', type: 'string'),
            ],
        ),
    ]
)]
class SaveCvProfileRequest extends FormRequest
{
    /** Date ISO `Y-m-d` ou chaîne vide (date absente du CV). */
    private const DATE_ISO = 'regex:/^(\d{4}-\d{2}-\d{2})?$/';

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
            'profile' => ['present', 'nullable', 'array'],
            'profile.firstName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.fullName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'profile.title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'profile.phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'profile.email' => ['sometimes', 'nullable', 'string', 'max:200'],
            'profile.birthDate' => ['sometimes', 'nullable', 'string', self::DATE_ISO],
            'profile.gender' => ['sometimes', 'nullable', 'string', Rule::in(['', 'Homme', 'Femme'])],
            'profile.desiredJob' => ['sometimes', 'nullable', 'string', 'max:200'],
            'profile.linkedin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.website' => ['sometimes', 'nullable', 'string', 'max:255'],

            'profile.skills' => ['sometimes', 'nullable', 'array', 'max:80'],
            'profile.skills.*' => ['nullable', 'string', 'max:150'],
            'profile.hobbies' => ['sometimes', 'nullable', 'array', 'max:40'],
            'profile.hobbies.*' => ['nullable', 'string', 'max:150'],

            'profile.experiences' => ['sometimes', 'nullable', 'array', 'max:30'],
            'profile.experiences.*' => ['array'],
            'profile.experiences.*.position' => ['sometimes', 'nullable', 'string', 'max:200'],
            'profile.experiences.*.company' => ['sometimes', 'nullable', 'string', 'max:200'],
            'profile.experiences.*.location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'profile.experiences.*.date' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.experiences.*.skills' => ['sometimes', 'nullable', 'array', 'max:40'],
            'profile.experiences.*.skills.*' => ['nullable', 'string', 'max:150'],
            'profile.experiences.*.missions' => ['sometimes', 'nullable', 'array', 'max:40'],
            'profile.experiences.*.missions.*' => ['nullable', 'string', 'max:1000'],

            'profile.educations' => ['sometimes', 'nullable', 'array', 'max:30'],
            'profile.educations.*' => ['array'],
            'profile.educations.*.title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'profile.educations.*.level' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.educations.*.school' => ['sometimes', 'nullable', 'string', 'max:200'],
            'profile.educations.*.date' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.educations.*.skills' => ['sometimes', 'nullable', 'array', 'max:40'],
            'profile.educations.*.skills.*' => ['nullable', 'string', 'max:150'],
        ];
    }
}
