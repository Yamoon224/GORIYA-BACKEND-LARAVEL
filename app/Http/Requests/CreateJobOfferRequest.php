<?php

namespace App\Http\Requests;

use App\Enums\JobExperienceType;
use App\Enums\JobStatus;
use App\Enums\JobType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateJobOfferRequest',
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
    ]
)]
class CreateJobOfferRequest extends FormRequest
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
            'location' => ['required', 'string'],
            'type' => ['required', Rule::enum(JobType::class)],
            'experience' => ['required', Rule::enum(JobExperienceType::class)],
            'salary' => ['required', 'string'],
            'description' => ['required', 'string'],
            'benefits' => ['required', 'string'],
            'requirements' => ['required', 'array'],
            'requirements.*' => ['string'],
            'status' => ['nullable', Rule::enum(JobStatus::class)],
            'publishDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
            // Pas de contrainte de type côté DTO Nest (@IsOptional() seul).
            'applicants' => ['nullable'],
            'companyId' => ['required', 'uuid'],
        ];
    }
}
