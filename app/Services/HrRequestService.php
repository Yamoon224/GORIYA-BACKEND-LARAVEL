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

    public function listFor(Employee $employee): Collection
    {
        return $employee->hrRequests()->with('decider')->orderByDesc('created_at')->get();
    }

    public function find(string $id, string $companyId): ?HrRequest
    {
        return HrRequest::where('company_id', $companyId)->with('decider')->find($id);
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
