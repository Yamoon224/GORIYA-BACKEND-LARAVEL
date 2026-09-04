<?php

namespace App\Http\Requests;

use App\Enums\JobExperienceType;
use App\Enums\JobQuestionType;
use App\Enums\JobStatus;
use App\Enums\JobType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateJobOfferRequest',
    // Hors brouillon : avec status = DRAFT, seuls title et companyId sont exigés.
    required: ['title', 'location', 'type', 'experience', 'salary', 'description', 'benefits', 'requirements', 'publishDate', 'endDate', 'companyId'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'location', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL']),
        new OA\Property(property: 'experience', type: 'string', enum: ['JUNIOR', 'INTERMEDIAIRE', 'SENIOR', 'EXPERT']),
        new OA\Property(property: 'salary', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'benefits', type: 'string'),
        new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'CLOSED', 'DRAFT'], nullable: true),
        new OA\Property(property: 'publishDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date'),
        new OA\Property(property: 'applicants', type: 'integer', nullable: true),
        new OA\Property(property: 'companyId', type: 'string', format: 'uuid'),
        new OA\Property(
            property: 'questions',
            description: 'Questions de présélection posées au candidat. Omis = inchangé, [] = supprimées.',
            type: 'array',
            nullable: true,
            items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'label', type: 'string'),
                new OA\Property(property: 'type', type: 'string', enum: ['TEXT', 'TEXTAREA', 'NUMBER', 'BOOLEAN', 'SINGLE_CHOICE', 'MULTI_CHOICE']),
                new OA\Property(property: 'required', type: 'boolean'),
                new OA\Property(property: 'options', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
            ], type: 'object')
        ),
    ]
)]
class CreateJobOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Un brouillon (status = DRAFT) s'enregistre incomplet : seuls le titre et
     * l'entreprise sont exigés, le reste est contrôlé au moment de publier
     * (JobOfferService::assertPublishable). Sans `status`, la colonne prend son
     * défaut ACTIVE : l'offre est publiée, donc tout est requis.
     */
    private function estBrouillon(): bool
    {
        return $this->input('status') === JobStatus::DRAFT->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requisSaufBrouillon = Rule::requiredIf(fn () => ! $this->estBrouillon());

        return [
            // Le titre identifie le brouillon dans la liste des annonces :
            // obligatoire même non publié.
            'title' => ['required', 'string'],
            'location' => [$requisSaufBrouillon, 'nullable', 'string'],
            'type' => [$requisSaufBrouillon, 'nullable', Rule::enum(JobType::class)],
            'experience' => [$requisSaufBrouillon, 'nullable', Rule::enum(JobExperienceType::class)],
            'salary' => [$requisSaufBrouillon, 'nullable', 'string'],
            'description' => [$requisSaufBrouillon, 'nullable', 'string'],
            'benefits' => [$requisSaufBrouillon, 'nullable', 'string'],
            'requirements' => [$requisSaufBrouillon, 'nullable', 'array'],
            'requirements.*' => ['string'],
            'status' => ['nullable', Rule::enum(JobStatus::class)],
            'publishDate' => [$requisSaufBrouillon, 'nullable', 'date'],
            'endDate' => [$requisSaufBrouillon, 'nullable', 'date'],
            // Pas de contrainte de type côté DTO Nest (@IsOptional() seul).
            'applicants' => ['nullable'],
            'companyId' => ['required', 'uuid'],
            // Questions de présélection façon LinkedIn : absentes du corps =
            // inchangées, tableau vide = toutes supprimées.
            'questions' => ['sometimes', 'nullable', 'array', 'max:20'],
            'questions.*.id' => ['nullable', 'uuid'],
            'questions.*.label' => ['required', 'string', 'max:500'],
            'questions.*.type' => ['nullable', Rule::enum(JobQuestionType::class)],
            'questions.*.required' => ['nullable', 'boolean'],
            'questions.*.options' => ['nullable', 'array', 'max:20'],
            'questions.*.options.*' => ['string', 'max:200'],
        ];
    }
}
