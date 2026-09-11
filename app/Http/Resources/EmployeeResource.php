<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Employee',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'source', type: 'string', enum: ['CANDIDATURE', 'MANUAL']),
        new OA\Property(property: 'candidatureId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'hiredFrom', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'candidatureId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'jobOfferId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'jobOfferTitle', type: 'string', nullable: true),
            new OA\Property(property: 'appliedDate', type: 'string', format: 'date', nullable: true),
        ]),
        new OA\Property(property: 'matricule', type: 'string'),
        new OA\Property(property: 'firstName', type: 'string'),
        new OA\Property(property: 'lastName', type: 'string'),
        new OA\Property(property: 'fullName', type: 'string'),
        new OA\Property(property: 'gender', type: 'string', nullable: true),
        new OA\Property(property: 'birthDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'jobTitle', type: 'string'),
        new OA\Property(property: 'department', type: 'string', nullable: true),
        new OA\Property(property: 'contractType', type: 'string'),
        new OA\Property(property: 'hireDate', type: 'string', format: 'date'),
        new OA\Property(property: 'contractEndDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'salary', type: 'integer', nullable: true),
        new OA\Property(property: 'annualLeaveDays', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'PROBATION', 'SUSPENDED', 'TERMINATED']),
        new OA\Property(property: 'onLeaveToday', type: 'boolean'),
        new OA\Property(property: 'manager', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'fullName', type: 'string'),
            new OA\Property(property: 'jobTitle', type: 'string'),
        ]),
        new OA\Property(property: 'pendingLeavesCount', type: 'integer'),
        new OA\Property(property: 'pendingRequestsCount', type: 'integer'),
        new OA\Property(property: 'emergencyContactName', type: 'string', nullable: true),
        new OA\Property(property: 'emergencyContactPhone', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'leaveBalance', type: 'object', nullable: true, description: 'Détail uniquement', properties: [
            new OA\Property(property: 'year', type: 'integer'),
            new OA\Property(property: 'entitlement', type: 'integer'),
            new OA\Property(property: 'taken', type: 'integer'),
            new OA\Property(property: 'pending', type: 'integer'),
            new OA\Property(property: 'remaining', type: 'integer'),
        ]),
        new OA\Property(property: 'activeContract', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'reference', type: 'string'),
            new OA\Property(property: 'kind', type: 'string'),
            new OA\Property(property: 'type', type: 'string'),
            new OA\Property(property: 'startDate', type: 'string', format: 'date'),
            new OA\Property(property: 'endDate', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'trialEndDate', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'signed', type: 'boolean'),
        ]),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class EmployeeResource extends JsonResource
{
    /** @var array{year: int, entitlement: int, taken: int, pending: int, remaining: int}|null */
    private ?array $leaveBalance = null;

    /**
     * Solde de congés, calculé seulement pour la fiche détail. Passé ici plutôt
     * que par `additional()` : l'API n'enveloppe pas ses resources, et
     * `additional()` réintroduirait une clé `data` pour cette seule réponse.
     *
     * @param  array{year: int, entitlement: int, taken: int, pending: int, remaining: int}  $balance
     */
    public function withLeaveBalance(array $balance): static
    {
        $this->leaveBalance = $balance;

        return $this;
    }

    /**
     * Identité minimale d'un employé, embarquée là où l'on affiche un congé ou
     * un solde hors de sa fiche (page Congés).
     *
     * @return array{id: string, fullName: string, firstName: string, lastName: string, matricule: string, jobTitle: string, department: ?string, status: ?string}
     */
    public static function summary(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'fullName' => $employee->full_name,
            'firstName' => $employee->first_name,
            'lastName' => $employee->last_name,
            'matricule' => $employee->matricule,
            'jobTitle' => $employee->job_title,
            'department' => $employee->department,
            'status' => $employee->status?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Origine de la fiche : embauche d'une candidature Goriya ou saisie manuelle.
            'source' => $this->candidature_id ? 'CANDIDATURE' : 'MANUAL',
            'candidatureId' => $this->candidature_id,
            'userId' => $this->user_id,
            'hiredFrom' => $this->whenLoaded('candidature', fn () => $this->candidature ? [
                'candidatureId' => $this->candidature->id,
                'jobOfferId' => $this->candidature->job_offer_id,
                'jobOfferTitle' => $this->candidature->jobOffer?->title,
                'appliedDate' => $this->candidature->applied_date?->toDateString(),
            ] : null),
            'matricule' => $this->matricule,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'fullName' => $this->full_name,
            'gender' => $this->gender,
            'birthDate' => $this->birth_date?->toDateString(),
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'jobTitle' => $this->job_title,
            'department' => $this->department,
            'contractType' => $this->contract_type?->value,
            // Dates renvoyées sans heure : un `datetime` UTC décalerait le jour
            // affiché selon le fuseau du navigateur.
            'hireDate' => $this->hire_date?->toDateString(),
            'contractEndDate' => $this->contract_end_date?->toDateString(),
            'salary' => $this->salary,
            'annualLeaveDays' => $this->annual_leave_days,
            'status' => $this->status?->value,
            'onLeaveToday' => $this->relationLoaded('currentLeaves') && $this->currentLeaves->isNotEmpty(),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'fullName' => $this->manager->full_name,
                'jobTitle' => $this->manager->job_title,
            ] : null),
            'pendingLeavesCount' => (int) ($this->pending_leaves_count ?? 0),
            'pendingRequestsCount' => (int) ($this->pending_requests_count ?? 0),
            'emergencyContactName' => $this->emergency_contact_name,
            'emergencyContactPhone' => $this->emergency_contact_phone,
            'notes' => $this->notes,
            'leaveBalance' => $this->when($this->leaveBalance !== null, fn () => $this->leaveBalance),
            // Contrat en vigueur : dit d'où viennent type, date de fin et salaire,
            // qui ne se modifient plus depuis la fiche tant qu'il existe.
            'activeContract' => $this->whenLoaded('activeContract', fn () => $this->activeContract ? [
                'id' => $this->activeContract->id,
                'reference' => $this->activeContract->reference,
                'kind' => $this->activeContract->kind?->value,
                'type' => $this->activeContract->type?->value,
                'startDate' => $this->activeContract->start_date?->toDateString(),
                'endDate' => $this->activeContract->end_date?->toDateString(),
                'trialEndDate' => $this->activeContract->trial_end_date?->toDateString(),
                'signed' => $this->activeContract->signed_at !== null || $this->activeContract->document_path !== null,
            ] : null),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
