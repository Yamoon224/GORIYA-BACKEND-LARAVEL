<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
                new OA\Property(property: 'indicatif', type: 'string', example: '+225'),
                new OA\Property(property: 'telephone', type: 'string', description: 'Numéro local, sans indicatif'),
                new OA\Property(property: 'adresse', type: 'string'),
                new OA\Property(property: 'profil', type: 'string'),
                new OA\Property(
                    property: 'experiences',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'entreprise', type: 'string'),
                        new OA\Property(property: 'poste', type: 'string'),
                        new OA\Property(property: 'dateDebut', type: 'string', format: 'date'),
                        new OA\Property(property: 'dateFin', type: 'string', format: 'date'),
                        new OA\Property(property: 'enCours', type: 'boolean'),
                        new OA\Property(property: 'description', type: 'string'),
                    ], type: 'object'),
                ),
                new OA\Property(
                    property: 'formations',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'etablissement', type: 'string'),
                        new OA\Property(
                            property: 'diplome',
                            type: 'string',
                            enum: ['', 'Bac', 'BTS', 'Licence', 'Master', 'Doctorat', 'Professorat'],
                        ),
                        new OA\Property(property: 'dateDebut', type: 'string', format: 'date'),
                        new OA\Property(property: 'dateFin', type: 'string', format: 'date'),
                    ], type: 'object'),
                ),
                new OA\Property(
                    property: 'competences',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'nom', type: 'string'),
                        new OA\Property(property: 'niveau', type: 'string'),
                    ], type: 'object'),
                ),
            ],
        ),
        new OA\Property(property: 'step', type: 'integer', minimum: 0, maximum: 20),
    ]
)]
class SaveCvRequest extends FormRequest
{
    /**
     * Date ISO `Y-m-d` ou chaîne vide : le formulaire s'enregistre tout seul,
     * y compris sur une entrée dont les dates ne sont pas encore renseignées.
     */
    private const DATE_ISO = 'regex:/^(\d{4}-\d{2}-\d{2})?$/';

    /**
     * Niveaux de diplôme acceptés (liste fermée côté formulaire), la chaîne
     * vide correspondant à « pas encore choisi ».
     *
     * @var list<string>
     */
    private const DIPLOMES = ['', 'Bac', 'BTS', 'Licence', 'Master', 'Doctorat', 'Professorat'];

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
            'data.indicatif' => ['sometimes', 'nullable', 'string', 'max:8'],
            'data.telephone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'data.adresse' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.profil' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'data.experiences' => ['sometimes', 'nullable', 'array', 'max:30'],
            'data.experiences.*' => ['array'],
            'data.experiences.*.entreprise' => ['sometimes', 'nullable', 'string', 'max:200'],
            'data.experiences.*.poste' => ['sometimes', 'nullable', 'string', 'max:200'],
            'data.experiences.*.dateDebut' => ['sometimes', 'nullable', 'string', self::DATE_ISO],
            'data.experiences.*.dateFin' => ['sometimes', 'nullable', 'string', self::DATE_ISO],
            'data.experiences.*.enCours' => ['sometimes', 'boolean'],
            'data.experiences.*.description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'data.formations' => ['sometimes', 'nullable', 'array', 'max:30'],
            'data.formations.*' => ['array'],
            'data.formations.*.etablissement' => ['sometimes', 'nullable', 'string', 'max:200'],
            'data.formations.*.diplome' => ['sometimes', 'nullable', 'string', Rule::in(self::DIPLOMES)],
            'data.formations.*.dateDebut' => ['sometimes', 'nullable', 'string', self::DATE_ISO],
            'data.formations.*.dateFin' => ['sometimes', 'nullable', 'string', self::DATE_ISO],

            'data.competences' => ['sometimes', 'nullable', 'array', 'max:60'],
            'data.competences.*' => ['array'],
            'data.competences.*.nom' => ['sometimes', 'nullable', 'string', 'max:120'],
            'data.competences.*.niveau' => ['sometimes', 'nullable', 'string', 'max:40'],

            'step' => ['sometimes', 'integer', 'min:0', 'max:20'],
        ];
    }
}
