<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PotentialPartner',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ncc', type: 'string', nullable: true),
        new OA\Property(property: 'companyName', type: 'string'),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'emailValid', type: 'boolean'),
        new OA\Property(property: 'contactName', type: 'string', nullable: true),
        new OA\Property(property: 'contactPhone', type: 'string', nullable: true),
        new OA\Property(property: 'sector', type: 'string', nullable: true),
        new OA\Property(property: 'activityLabel', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'commune', type: 'string', nullable: true),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'companySize', type: 'string', nullable: true),
        new OA\Property(property: 'caTranche', type: 'string', nullable: true),
        new OA\Property(property: 'workforceTranche', type: 'string', nullable: true),
        new OA\Property(property: 'firstExerciseYear', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'source', type: 'string'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'unsubscribedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'lastContactedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class PotentialPartnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ncc' => $this->ncc,
            'companyName' => $this->company_name,
            'email' => $this->email,
            'emailValid' => $this->email_valid,
            'contactName' => $this->contact_name,
            'contactPhone' => $this->contact_phone,
            'sector' => $this->sector,
            'activityLabel' => $this->activity_label,
            'city' => $this->city,
            'commune' => $this->commune,
            'address' => $this->address,
            'companySize' => $this->company_size,
            'caTranche' => $this->ca_tranche,
            'workforceTranche' => $this->workforce_tranche,
            'firstExerciseYear' => $this->first_exercise_year,
            'status' => $this->status->value,
            'source' => $this->source,
            'notes' => $this->notes,
            'unsubscribedAt' => $this->unsubscribed_at,
            'lastContactedAt' => $this->last_contacted_at,
            'createdAt' => $this->created_at,
        ];
    }
}
