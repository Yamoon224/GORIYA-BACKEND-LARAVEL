<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/job-offers/dto/job-offer.vm.ts.
 */
#[OA\Schema(
    schema: 'JobOffer',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'slug', type: 'string', description: 'Identifiant lisible utilisé dans les URLs publiques'),
        new OA\Property(property: 'location', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL'], nullable: true),
        new OA\Property(property: 'experience', type: 'string', enum: ['JUNIOR', 'INTERMEDIAIRE', 'SENIOR', 'EXPERT'], nullable: true),
        new OA\Property(property: 'salary', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'benefits', type: 'string'),
        new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'CLOSED', 'DRAFT']),
        new OA\Property(property: 'publishDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date'),
        new OA\Property(property: 'applicants', type: 'integer'),
        new OA\Property(property: 'image', type: 'string', nullable: true),
        new OA\Property(property: 'remote', type: 'boolean'),
        new OA\Property(
            property: 'company',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'slug', type: 'string'),
                new OA\Property(property: 'logo', type: 'string', nullable: true),
                new OA\Property(property: 'coverImage', type: 'string', nullable: true),
                new OA\Property(property: 'sector', type: 'string', nullable: true),
            ]
        ),
        new OA\Property(property: 'questions', type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOfferQuestion')),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class JobOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'location' => $this->location,
            // Nullables depuis les brouillons : une offre non publiée peut
            // n'avoir encore ni type de contrat ni niveau d'expérience.
            'type' => $this->type?->value,
            'experience' => $this->experience?->value,
            'salary' => $this->salary,
            'description' => $this->description,
            'benefits' => $this->benefits,
            'requirements' => $this->requirements,
            'status' => $this->status->value,
            'publishDate' => $this->publish_date,
            'endDate' => $this->end_date,
            'applicants' => $this->applicants,
            'image' => MediaUrl::resolve($this->image),
            'remote' => (bool) $this->remote,
            'company' => $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'slug' => $this->company->slug,
                'logo' => MediaUrl::resolve($this->company->logo),
                'coverImage' => MediaUrl::resolve($this->company->cover_image),
                'sector' => $this->company->sector,
            ] : null,
            // Toujours un tableau (jamais null) : le wizard de candidature teste
            // simplement sa longueur pour savoir s'il affiche l'étape questions.
            'questions' => JobOfferQuestionResource::collection($this->whenLoaded('questions', fn () => $this->questions, fn () => $this->questions()->get()))->resolve(),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
