<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\HrWorkflowStatus;
use App\Enums\LeaveType;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Congés d'un employé : saisie, décision (approbation, refus, annulation) et
 * garde-fous — pas de chevauchement, pas de congé payé au-delà du solde.
 */
class EmployeeLeaveService
{
    /**
     * Transitions autorisées depuis chaque statut. Un congé approuvé peut
     * encore être annulé (retour anticipé, report) ; un refus ou une
     * annulation sont définitifs.
     */
    private const TRANSITIONS = [
        'PENDING' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['CANCELLED'],
    ];

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly NotificationService $notifications,
    ) {}

    public function listFor(Employee $employee): Collection
    {
        return $employee->leaves()->with('decider')->orderByDesc('start_date')->get();
    }

    /**
     * Congés de toute l'entreprise, avec l'employé concerné : alimente la page
     * Congés (liste à valider, planning). Filtres cumulables ; la période retient
     * tout congé qui la chevauche, pas seulement ceux qui y commencent.
     *
     * @param  array{status?: ?list<string>, type?: ?list<string>, employeeId?: ?string, department?: ?string, search?: ?string, from?: ?string, to?: ?string}  $filters
     */
    public function listForCompany(string $companyId, array $filters = []): Collection
    {
        $query = EmployeeLeave::query()
            ->where('company_id', $companyId)
            ->with(['decider', 'employee']);

        if (! empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->whereIn('type', $filters['type']);
        }
        if ($employeeId = $filters['employeeId'] ?? null) {
            $query->where('employee_id', $employeeId);
        }
        if ($from = $filters['from'] ?? null) {
            $query->whereDate('end_date', '>=', $from);
        }
        if ($to = $filters['to'] ?? null) {
            $query->whereDate('start_date', '<=', $to);
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

        return $query->orderByDesc('start_date')->get();
    }

    public function find(string $id, string $companyId): ?EmployeeLeave
    {
        return EmployeeLeave::where('company_id', $companyId)->with('decider')->find($id);
    }

    /**
     * @param  array{type: string, startDate: string, endDate: string, reason?: ?string}  $data
     */
    public function create(Employee $employee, User $author, array $data): EmployeeLeave
    {
        if ($employee->status === EmployeeStatus::TERMINATED) {
            abort(400, "Impossible de poser un congé : l'employé a quitté l'entreprise.");
        }

        $start = CarbonImmutable::parse($data['startDate'])->startOfDay();
        $end = CarbonImmutable::parse($data['endDate'])->startOfDay();
        $days = self::businessDays($start, $end);

        if ($days === 0) {
            abort(400, 'La période choisie ne contient aucun jour ouvré.');
        }

        $overlap = $employee->leaves()
            ->whereIn('status', [HrWorkflowStatus::PENDING->value, HrWorkflowStatus::APPROVED->value])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
        if ($overlap) {
            abort(400, 'Cette période chevauche un congé déjà posé pour cet employé.');
        }

        $leave = EmployeeLeave::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'type' => $data['type'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'reason' => $data['reason'] ?? null,
            'status' => HrWorkflowStatus::PENDING,
            'created_by' => $author->id,
        ]);

        return $leave->load('decider');
    }

    public function decide(EmployeeLeave $leave, User $decider, HrWorkflowStatus $status, ?string $comment): EmployeeLeave
    {
        $allowed = self::TRANSITIONS[$leave->status->value] ?? [];
        if (! in_array($status->value, $allowed, true)) {
            abort(400, 'Ce congé ne peut plus passer à ce statut.');
        }

        if ($status === HrWorkflowStatus::APPROVED && $leave->type === LeaveType::PAID) {
            $balance = $this->employees->leaveBalance($leave->employee, (int) $leave->start_date->year);
            if ($leave->days > $balance['remaining']) {
                abort(400, sprintf(
                    'Solde insuffisant : %d jour(s) restant(s) pour %d jour(s) demandé(s).',
                    max(0, $balance['remaining']),
                    $leave->days,
                ));
            }
        }

        $leave->update([
            'status' => $status,
            'decided_by' => $decider->id,
            'decided_at' => now(),
            'decision_comment' => $comment,
        ]);

        $this->notifications->notifyLeaveDecided($leave);

        return $leave->load('decider');
    }

    /**
     * Un congé approuvé fait partie de l'historique (paie, solde) : on l'annule,
     * on ne l'efface pas.
     */
    public function delete(EmployeeLeave $leave): void
    {
        if ($leave->status === HrWorkflowStatus::APPROVED) {
            abort(400, "Un congé approuvé ne se supprime pas : annulez-le d'abord.");
        }

        $leave->delete();
    }

    /**
     * Jours ouvrés (lundi à vendredi) entre deux dates incluses. Les jours
     * fériés ne sont pas décomptés : ils varient d'un pays à l'autre et d'une
     * année à l'autre (fêtes religieuses).
     */
    public static function businessDays(CarbonInterface $start, CarbonInterface $end): int
    {
        $days = 0;
        for ($day = CarbonImmutable::instance($start); $day->lte($end); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $days++;
            }
        }

        return $days;
    }
}
