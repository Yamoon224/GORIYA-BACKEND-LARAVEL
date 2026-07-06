<?php

namespace App\Http\Requests;

use App\Enums\JobExperienceType;
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
        return [
            'title' => ['sometimes', 'string'],
            'location' => ['sometimes', 'string'],
            'type' => ['nullable', Rule::enum(JobType::class)],
            'experience' => ['nullable', Rule::enum(JobExperienceType::class)],
            'salary' => ['sometimes', 'string'],
            'description' => ['sometimes', 'string'],
            'benefits' => ['sometimes', 'string'],
            'requirements' => ['sometimes', 'array'],
            'requirements.*' => ['string'],
            'status' => ['nullable', Rule::enum(JobStatus::class)],
            'publishDate' => ['sometimes', 'date'],
            'endDate' => ['sometimes', 'date'],
            'applicants' => ['nullable'],
            'companyId' => ['nullable', 'uuid'],
        ];
    }
}
