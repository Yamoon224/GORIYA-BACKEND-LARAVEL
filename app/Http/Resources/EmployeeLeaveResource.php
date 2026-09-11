<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EmployeeLeave',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employeeId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['PAID', 'SICK', 'MATERNITY', 'PATERNITY', 'FAMILY_EVENT', 'UNPAID', 'OTHER']),
        new OA\Property(property: 'startDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date'),
        new OA\Property(property: 'days', type: 'integer', description: 'Jours ouvrés'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED']),
        new OA\Property(property: 'decidedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'decidedByName', type: 'string', nullable: true),
        new OA\Property(property: 'decisionComment', type: 'string', nullable: true),
        new OA\Property(property: 'employee', type: 'object', nullable: true, description: "Liste de l'entreprise uniquement", properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'fullName', type: 'string'),
            new OA\Property(property: 'firstName', type: 'string'),
            new OA\Property(property: 'lastName', type: 'string'),
            new OA\Property(property: 'matricule', type: 'string'),
            new OA\Property(property: 'jobTitle', type: 'string'),
            new OA\Property(property: 'department', type: 'string', nullable: true),
            new OA\Property(property: 'status', type: 'string'),
        ]),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class EmployeeLeaveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'type' => $this->type?->value,
            'startDate' => $this->start_date?->toDateString(),
            'endDate' => $this->end_date?->toDateString(),
            'days' => $this->days,
            'reason' => $this->reason,
            'status' => $this->status?->value,
            'decidedAt' => $this->decided_at,
            'decidedByName' => $this->whenLoaded('decider', fn () => $this->decider?->name),
            'decisionComment' => $this->decision_comment,
            // Présent sur la liste de l'entreprise (page Congés) : on y lit un
            // congé sans être sur la fiche de l'employé.
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? EmployeeResource::summary($this->employee) : null),
            'createdAt' => $this->created_at,
        ];
    }
}
