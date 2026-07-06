<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/companies/dto/company.vm.ts.
 */
#[OA\Schema(
    schema: 'Company',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'sector', type: 'string'),
        new OA\Property(property: 'logo', type: 'string', nullable: true),
        new OA\Property(property: 'coverImage', type: 'string', nullable: true),
        new OA\Property(property: 'about', type: 'string', nullable: true),
        new OA\Property(property: 'website', type: 'string', nullable: true),
        new OA\Property(property: 'creationDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'partnershipDate', type: 'string', format: 'date'),
        new OA\Property(property: 'companySize', type: 'string', nullable: true),
        new OA\Property(property: 'socialLinks', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'headquarters', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'SUSPENDED']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sector' => $this->sector,
            'logo' => $this->logo,
            'coverImage' => $this->cover_image,
            'about' => $this->about,
            'website' => $this->website,
            'creationDate' => $this->creation_date,
            'partnershipDate' => $this->partnership_date,
            'companySize' => $this->company_size,
            'socialLinks' => $this->social_links,
            'country' => $this->country,
            'headquarters' => $this->headquarters,
            'location' => $this->location,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status->value,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
