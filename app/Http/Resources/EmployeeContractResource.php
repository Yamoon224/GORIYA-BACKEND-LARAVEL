<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EmployeeContract',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employeeId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee', type: 'object', nullable: true, description: "Liste de l'entreprise uniquement"),
        new OA\Property(property: 'reference', type: 'string', example: 'CTR-0001'),
        new OA\Property(property: 'kind', type: 'string', enum: ['INITIAL', 'RENEWAL', 'AMENDMENT']),
        new OA\Property(property: 'type', type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL']),
        new OA\Property(property: 'jobTitle', type: 'string'),
        new OA\Property(property: 'startDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'trialEndDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'salary', type: 'integer', nullable: true),
        new OA\Property(property: 'weeklyHours', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'ACTIVE', 'ENDED', 'TERMINATED']),
        new OA\Property(property: 'signedAt', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'terminationDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'terminationReason', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'document', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'uploadedAt', type: 'string', format: 'date-time', nullable: true),
        ]),
        new OA\Property(property: 'parent', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'reference', type: 'string'),
            new OA\Property(property: 'kind', type: 'string'),
        ]),
        new OA\Property(property: 'createdByName', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class EmployeeContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? EmployeeResource::summary($this->employee) : null),
            'reference' => $this->reference,
            'kind' => $this->kind?->value,
            'type' => $this->type?->value,
            'jobTitle' => $this->job_title,
            'startDate' => $this->start_date?->toDateString(),
            'endDate' => $this->end_date?->toDateString(),
            'trialEndDate' => $this->trial_end_date?->toDateString(),
            'salary' => $this->salary,
            'weeklyHours' => $this->weekly_hours,
            'status' => $this->status?->value,
            'signedAt' => $this->signed_at?->toDateString(),
            'terminationDate' => $this->termination_date?->toDateString(),
            'terminationReason' => $this->termination_reason,
            'notes' => $this->notes,
            // Jamais le chemin de stockage : le fichier se télécharge par l'API,
            // qui revérifie l'entreprise de l'appelant.
            'document' => $this->document_path ? [
                'name' => $this->document_name,
                'uploadedAt' => $this->document_uploaded_at,
            ] : null,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'reference' => $this->parent->reference,
                'kind' => $this->parent->kind?->value,
            ] : null),
            'createdByName' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
