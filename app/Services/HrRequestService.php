<?php

namespace App\Services;

use App\Enums\HrWorkflowStatus;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Demandes RH d'un employé (attestations, avances, formations…). Contrairement
 * aux congés, une demande reste possible pour un employé parti : une
 * attestation de travail se réclame précisément après le départ.
 */
class HrRequestService
{
    /** Transitions autorisées : APPROVED, REJECTED et CANCELLED sont finaux. */
    private const TRANSITIONS = [
        'PENDING' => ['IN_PROGRESS', 'APPROVED', 'REJECTED', 'CANCELLED'],
        'IN_PROGRESS' => ['APPROVED', 'REJECTED', 'CANCELLED'],
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    public function listFor(Employee $employee): Collection
    {
        return $employee->hrRequests()->with(['decider', 'document'])->orderByDesc('created_at')->get();
    }

    /**
     * Demandes RH de toute l'entreprise, avec l'employé concerné : alimente
     * la page Demandes RH — jusqu'ici, une demande n'était visible que depuis
     * la fiche de son employé, sans vue d'ensemble côté entreprise.
     *
     * @param  array{status?: ?list<string>, type?: ?list<string>, employeeId?: ?string, department?: ?string, search?: ?string}  $filters
     */
    public function listForCompany(string $companyId, array $filters = []): Collection
    {
        $query = HrRequest::query()
            ->where('company_id', $companyId)
            ->with(['decider', 'document', 'employee']);

        if (! empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->whereIn('type', $filters['type']);
        }
        if ($employeeId = $filters['employeeId'] ?? null) {
            $query->where('employee_id', $employeeId);
        }
        if ($department = $filters['department'] ?? null) {
            $query->whereHas('employee', fn ($q) => $q->where('department', $department));
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->whereHas('employee', fn ($q) => $q->where(function ($w) use ($search) {
                foreach (['first_name', 'last_name', 'matricule'] as $column) {
                    $w->orWhere($column, 'like', "%{$search}%");
                }
            }));
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function find(string $id, string $companyId): ?HrRequest
    {
        return HrRequest::where('company_id', $companyId)->with(['decider', 'document'])->find($id);
    }

    /**
     * @param  array{type: string, subject: string, description?: ?string, amount?: ?int}  $data
     */
    public function create(Employee $employee, User $author, array $data): HrRequest
    {
        $hrRequest = HrRequest::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'] ?? null,
            'status' => HrWorkflowStatus::PENDING,
            'created_by' => $author->id,
        ]);

        return $hrRequest->load('decider');
    }

    public function decide(HrRequest $hrRequest, User $decider, HrWorkflowStatus $status, ?string $comment): HrRequest
    {
        $allowed = self::TRANSITIONS[$hrRequest->status->value] ?? [];
        if (! in_array($status->value, $allowed, true)) {
            abort(400, 'Cette demande ne peut plus passer à ce statut.');
        }

        // « En cours » n'est pas une décision : on ne signe pas la prise en charge.
        $isDecision = $status !== HrWorkflowStatus::IN_PROGRESS;

        $hrRequest->update([
            'status' => $status,
            'decided_by' => $isDecision ? $decider->id : null,
            'decided_at' => $isDecision ? now() : null,
            'decision_comment' => $comment ?? $hrRequest->decision_comment,
        ]);

        $this->notifications->notifyHrRequestDecided($hrRequest);

        return $hrRequest->load('decider');
    }

    public function delete(HrRequest $hrRequest): void
    {
        if ($hrRequest->status === HrWorkflowStatus::APPROVED) {
            abort(400, 'Une demande approuvée fait partie du dossier et ne se supprime pas.');
        }

        $hrRequest->delete();
    }
}
