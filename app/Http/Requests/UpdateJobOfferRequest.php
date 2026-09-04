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
    schema: 'UpdateJobOfferRequest',
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'location', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL'], nullable: true),
        new OA\Property(property: 'experience', type: 'string', enum: ['JUNIOR', 'INTERMEDIAIRE', 'SENIOR', 'EXPERT'], nullable: true),
        new OA\Property(property: 'salary', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'benefits', type: 'string'),
        new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'CLOSED', 'DRAFT'], nullable: true),
        new OA\Property(property: 'publishDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date'),
        new OA\Property(property: 'applicants', type: 'integer', nullable: true),
        new OA\Property(property: 'companyId', type: 'string', format: 'uuid', nullable: true),
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
class UpdateJobOfferRequest extends FormRequest
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
        // `nullable` partout sauf le titre : un brouillon en cours de rédaction
        // doit pouvoir voir un champ revidé. La complétude exigée d'une offre
        // publiée est vérifiée à la publication (JobOfferService::update).
        return [
            'title' => ['sometimes', 'string'],
            'location' => ['sometimes', 'nullable', 'string'],
            'type' => ['nullable', Rule::enum(JobType::class)],
            'experience' => ['nullable', Rule::enum(JobExperienceType::class)],
            'salary' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'benefits' => ['sometimes', 'nullable', 'string'],
            'requirements' => ['sometimes', 'nullable', 'array'],
            'requirements.*' => ['string'],
            'status' => ['nullable', Rule::enum(JobStatus::class)],
            'publishDate' => ['sometimes', 'nullable', 'date'],
            'endDate' => ['sometimes', 'nullable', 'date'],
            'applicants' => ['nullable'],
            'companyId' => ['nullable', 'uuid'],
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
