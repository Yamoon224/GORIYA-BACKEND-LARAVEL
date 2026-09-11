<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'HrRequest',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employeeId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['WORK_CERTIFICATE', 'SALARY_CERTIFICATE', 'SALARY_ADVANCE', 'TRAINING', 'EQUIPMENT', 'SCHEDULE_CHANGE', 'OTHER']),
        new OA\Property(property: 'subject', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['PENDING', 'IN_PROGRESS', 'APPROVED', 'REJECTED', 'CANCELLED']),
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
class HrRequestResource extends JsonResource
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
            'subject' => $this->subject,
            'description' => $this->description,
            'amount' => $this->amount,
            'status' => $this->status?->value,
            'decidedAt' => $this->decided_at,
            'decidedByName' => $this->whenLoaded('decider', fn () => $this->decider?->name),
            'decisionComment' => $this->decision_comment,
            // Présent sur la liste de l'entreprise (page Demandes RH) : on y
            // lit une demande sans être sur la fiche de l'employé.
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? EmployeeResource::summary($this->employee) : null),
            // Avance sur salaire déjà retenue sur un bulletin de paie validé.
            'payslipId' => $this->payslip_id,
            // Attestation délivrée en réponse à la demande (Documents RH).
            'document' => $this->whenLoaded('document', fn () => $this->document ? [
                'id' => $this->document->id,
                'title' => $this->document->title,
                'fileName' => $this->document->file_name,
            ] : null),
            'createdAt' => $this->created_at,
        ];
    }
}
