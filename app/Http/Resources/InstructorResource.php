<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Instructor',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'headline', type: 'string', nullable: true),
        new OA\Property(property: 'bio', type: 'string', nullable: true),
        new OA\Property(property: 'photoUrl', type: 'string', nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'linkedinUrl', type: 'string', nullable: true),
        new OA\Property(property: 'isActive', type: 'boolean'),
    ]
)]
class InstructorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'photoPath' => $this->photo_path,
            'photoUrl' => MediaUrl::resolve($this->photo_path),
            'country' => $this->country,
            'linkedinUrl' => $this->linkedin_url,
            'isActive' => $this->is_active,
            'coursesCount' => $this->courses_count ?? null,
        ];
    }
}
