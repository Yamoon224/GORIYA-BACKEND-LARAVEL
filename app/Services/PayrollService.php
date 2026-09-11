<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\HrRequestType;
use App\Enums\HrWorkflowStatus;
use App\Enums\LeaveType;
use App\Enums\PayrollRunStatus;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Périodes de paie et bulletins.
 *
 * Règles :
 *  - une période par mois, traitées dans l'ordre : pas de nouvelle période tant
 *    qu'une autre est en brouillon, ni avant une période déjà existante ;
 *  - un brouillon se recalcule à volonté (les primes et retenues saisies sont
 *    conservées) ; la validation fige les bulletins et marque les avances sur
 *    salaire comme retenues, pour qu'elles ne le soient qu'une fois ;
 *  - seule la dernière période validée, et pas encore payée, peut être rouverte.
 *
 * Les données viennent du reste des Services RH : salaire de la fiche (reflet du
 * contrat en vigueur), dates d'embauche et de fin de contrat (prorata), congés
 * sans solde approuvés, avances sur salaire approuvées.
 */
class PayrollService
{
    public function __construct(
        private readonly PayrollSettingsService $settings,
        private readonly PayrollCalculator $calculator,
    ) {}

    public function listRuns(string $companyId): Collection
    {
        return PayrollRun::where('company_id', $companyId)->orderByDesc('year')->orderByDesc('month')->get();
    }

    public function findRun(string $id, string $companyId): ?PayrollRun
    {
        $run = PayrollRun::where('company_id', $companyId)->with('payslips')->find($id);

        return $run ? $this->sortPayslips($run) : null;
    }

    public function findPayslip(string $id, string $companyId): ?Payslip
    {
        return Payslip::where('company_id', $companyId)->with('run')->find($id);
    }

    public function payslipsOf(Employee $employee): \Illuminate\Support\Collection
    {
        return Payslip::where('employee_id', $employee->id)
            ->with('run')
            ->get()
            ->sortByDesc(fn (Payslip $p) => ($p->run?->year ?? 0) * 100 + ($p->run?->month ?? 0))
            ->values();
    }

    public function createRun(string $companyId, User $author, int $year, int $month): PayrollRun
    {
        $period = CarbonImmutable::create($year, $month, 1)->startOfDay();
        if ($period->greaterThan(CarbonImmutable::now()->startOfMonth()->addMonth())) {
            abort(400, 'La paie ne se prépare pas au-delà du mois prochain.');
        }
        if (PayrollRun::where('company_id', $companyId)->where('year', $year)->where('month', $month)->exists()) {
            abort(400, 'La paie de '.$this->monthLabel($year, $month).' existe déjà.');
        }

        $draft = PayrollRun::where('company_id', $companyId)->where('status', PayrollRunStatus::DRAFT->value)->first();
        if ($draft) {
            abort(400, 'La paie de '.$this->monthLabel($draft->year, $draft->month)." est encore en brouillon : validez-la ou supprimez-la avant d'en préparer une autre.");
        }
        if ($this->laterRunExists($companyId, $year, $month)) {
            abort(400, 'Une paie plus récente existe déjà : les périodes se traitent dans l’ordre.');
        }

        return DB::transaction(function () use ($companyId, $author, $year, $month) {
            $run = PayrollRun::create([
                'company_id' => $companyId,
                'year' => $year,
                'month' => $month,
                'status' => PayrollRunStatus::DRAFT,
                'created_by' => $author->id,
            ]);
            $this->syncPayslips($run);

            return $this->reloadRun($run);
        });
    }

    public function recomputeRun(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);
        DB::transaction(fn () => $this->syncPayslips($run));

        return $this->reloadRun($run);
    }

    /**
     * @param  array{bonuses: list<array<string, mixed>>, deductions: list<array<string, mixed>>}  $data
     */
    public function updateAdjustments(Payslip $payslip, array $data): Payslip
    {
        $run = $payslip->run;
        $this->assertDraft($run);

        $employee = $payslip->employee;
        if (! $employee) {
            abort(400, "L'employé de ce bulletin a été supprimé : recalculez la paie.");
        }

        DB::transaction(function () use ($payslip, $employee, $run, $data) {
            $payslip->bonuses = array_map(fn (array $b) => [
                'label' => trim($b['label']),
                'amount' => (int) $b['amount'],
                'taxable' => (bool) ($b['taxable'] ?? true),
                'subjectToContributions' => (bool) ($b['subjectToContributions'] ?? true),
            ], array_values($data['bonuses']));
            $payslip->deductions = array_map(fn (array $d) => [
                'label' => trim($d['label']),
                'amount' => (int) $d['amount'],
            ], array_values($data['deductions']));

            [$start, $end] = $this->periodBounds($run);
            $this->computePayslip($payslip, $employee, $start, $end, $this->settings->forCompany($run->company_id));
            $this->refreshTotals($run);
        });

        return Payslip::with('run')->findOrFail($payslip->id);
    }

    public function validateRun(PayrollRun $run, User $user): PayrollRun
    {
        $this->assertDraft($run);

        DB::transaction(function () use ($run, $user) {
            // Dernier recalcul : la paie validée reflète les données du jour.
            $this->syncPayslips($run);
            $payslips = $run->payslips()->get();

            if ($payslips->isEmpty()) {
                abort(400, "Aucun bulletin à valider : aucun employé n'est à payer sur cette période.");
            }
            $blocked = $payslips->filter(fn (Payslip $p) => collect($p->warnings)->contains(fn ($w) => ! empty($w['blocking'])));
            if ($blocked->isNotEmpty()) {
                abort(400, 'Bulletins à compléter avant validation : '.$blocked->map(fn (Payslip $p) => $p->employee_snapshot['fullName'] ?? '?')->join(', ').'.');
            }

            foreach ($payslips as $payslip) {
                if (! empty($payslip->advance_ids)) {
                    HrRequest::whereIn('id', $payslip->advance_ids)->whereNull('payslip_id')->update(['payslip_id' => $payslip->id]);
                }
            }

            $run->update([
                'status' => PayrollRunStatus::VALIDATED,
                'validated_at' => now(),
                'validated_by' => $user->id,
            ]);
        });

        return $this->reloadRun($run);
    }

    public function reopenRun(PayrollRun $run): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::VALIDATED) {
            abort(400, 'Seule une paie validée et pas encore payée peut être rouverte.');
        }
        if ($this->laterRunExists($run->company_id, $run->year, $run->month)) {
            abort(400, 'Une paie plus récente existe : seule la dernière période se rouvre.');
        }

        DB::transaction(function () use ($run) {
            // Les avances redeviennent à retenir : le prochain calcul les reprendra.
            HrRequest::whereIn('payslip_id', $run->payslips()->pluck('id'))->update(['payslip_id' => null]);
            $run->update(['status' => PayrollRunStatus::DRAFT, 'validated_at' => null, 'validated_by' => null]);
        });

        return $this->reloadRun($run);
    }

    public function markPaid(PayrollRun $run, User $user, string $paymentDate, ?string $reference): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::VALIDATED) {
            abort(400, "La paie doit être validée avant d'être marquée comme payée.");
        }

        $run->update([
            'status' => PayrollRunStatus::PAID,
            'payment_date' => $paymentDate,
            'payment_reference' => $reference,
            'paid_at' => now(),
            'paid_by' => $user->id,
        ]);

        return $this->reloadRun($run);
    }

    public function deleteRun(PayrollRun $run): void
    {
        $this->assertDraft($run, 'Seule une paie en brouillon se supprime.');
        $run->delete();
    }

    /**
     * Journal de paie au format CSV (séparateur « ; », BOM UTF-8) : s'ouvre tel
     * quel dans Excel en français, pour le comptable.
     */
    public function exportCsv(PayrollRun $run): string
    {
        $run = $this->sortPayslips($run->loadMissing('payslips'));
        $rows = [[
            'Matricule', 'Nom', 'Poste', 'Jours payés', 'Salaire brut', 'Cotisations salariales', 'Impôt',
            'Avances', 'Autres retenues', 'Net à payer', 'Cotisations patronales', 'Coût employeur',
        ]];

        foreach ($run->payslips as $payslip) {
            $employee = $payslip->employee_snapshot;
            $rows[] = [
                $employee['matricule'] ?? '',
                $employee['fullName'] ?? '',
                $employee['jobTitle'] ?? '',
                $payslip->worked_days,
                $payslip->gross,
                $payslip->employee_contributions,
                $payslip->tax,
                $payslip->advances,
                $payslip->other_deductions,
                $payslip->net,
                $payslip->employer_contributions,
                $payslip->employer_cost,
            ];
        }

        $rows[] = [
            '', 'TOTAL', '', '',
            $run->total_gross, $run->total_employee_contributions, $run->total_tax,
            $run->payslips->sum('advances'), $run->payslips->sum('other_deductions'),
            $run->total_net, $run->total_employer_contributions, $run->total_employer_cost,
        ];

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function monthLabel(int $year, int $month): string
    {
        return CarbonImmutable::create($year, $month, 1)->locale('fr')->isoFormat('MMMM YYYY');
    }

    /**
     * Aligne les bulletins d'un brouillon sur les employés à payer ce mois-ci et
     * recalcule chacun. Primes et retenues saisies sont conservées.
     */
    private function syncPayslips(PayrollRun $run): void
    {
        [$start, $end] = $this->periodBounds($run);
        $settings = $this->settings->forCompany($run->company_id);
        $employees = $this->payableEmployees($run->company_id, $start, $end);
        $ids = $employees->pluck('id')->all();

        // Bulletins d'employés qui ne sont plus à payer (fin de contrat corrigée, fiche supprimée…).
        $run->payslips()
            ->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhereNotIn('employee_id', $ids))
            ->delete();

        $existing = $run->payslips()->get()->keyBy('employee_id');
        foreach ($employees as $employee) {
            $payslip = $existing->get($employee->id) ?? new Payslip([
                'company_id' => $run->company_id,
                'payroll_run_id' => $run->id,
                'employee_id' => $employee->id,
                'bonuses' => [],
                'deductions' => [],
            ]);
            $this->computePayslip($payslip, $employee, $start, $end, $settings);
        }

        $this->refreshTotals($run);
    }

    /**
     * Employés à payer sur la période : embauchés avant sa fin, dont le contrat
     * n'était pas terminé avant son début. Un employé parti n'est payé que si sa
     * fin de contrat tombe dans la période.
     */
    private function payableEmployees(string $companyId, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereDate('hire_date', '<=', $end->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('contract_end_date')->orWhereDate('contract_end_date', '>=', $start->toDateString()))
            ->where(fn (Builder $q) => $q
                ->where('status', '!=', EmployeeStatus::TERMINATED->value)
                ->orWhereNotNull('contract_end_date'))
            ->with('activeContract')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function computePayslip(Payslip $payslip, Employee $employee, CarbonImmutable $start, CarbonImmutable $end, array $settings): void
    {
        $inputs = $this->inputsFor($employee, $start, $end);
        $result = $this->calculator->compute($inputs + [
            'bonuses' => $payslip->bonuses ?? [],
            'deductions' => $payslip->deductions ?? [],
        ], $settings);

        $payslip->fill([
            'employee_snapshot' => $this->snapshot($employee),
            'base_salary' => $inputs['baseSalary'],
            'business_days' => $inputs['businessDays'],
            'worked_days' => max(0, $inputs['employedDays'] - $inputs['unpaidLeaveDays']),
            'unpaid_leave_days' => $inputs['unpaidLeaveDays'],
            'advance_ids' => array_column($inputs['advances'], 'id'),
            'lines' => $result['lines'],
            'warnings' => $inputs['warnings'],
            'gross' => $result['gross'],
            'employee_contributions' => $result['employeeContributions'],
            'employer_contributions' => $result['employerContributions'],
            'taxable' => $result['taxable'],
            'tax' => $result['tax'],
            'advances' => $result['advances'],
            'other_deductions' => $result['otherDeductions'],
            'net' => $result['net'],
            'employer_cost' => $result['employerCost'],
        ])->save();
    }

    /**
     * @return array{baseSalary: int, businessDays: int, employedDays: int, unpaidLeaveDays: int, advances: list<array{id: string, label: string, amount: int}>, warnings: list<array{code: string, message: string, blocking: bool}>}
     */
    private function inputsFor(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $businessDays = EmployeeLeaveService::businessDays($start, $end);

        // Jours couverts par le contrat dans le mois : de l'embauche (ou du 1er)
        // à la fin de contrat (ou au dernier jour).
        $hired = CarbonImmutable::instance($employee->hire_date)->startOfDay();
        $from = $hired->greaterThan($start) ? $hired : $start;
        $to = $end;
        if ($employee->contract_end_date) {
            $contractEnd = CarbonImmutable::instance($employee->contract_end_date)->startOfDay();
            if ($contractEnd->lessThan($end)) {
                $to = $contractEnd;
            }
        }
        $employedDays = $from->lessThanOrEqualTo($to) ? EmployeeLeaveService::businessDays($from, $to) : 0;

        $unpaidDays = 0;
        if ($employedDays > 0) {
            $leaves = $employee->leaves()
                ->where('status', HrWorkflowStatus::APPROVED->value)
                ->where('type', LeaveType::UNPAID->value)
                ->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString())
                ->get();
            foreach ($leaves as $leave) {
                $leaveStart = CarbonImmutable::instance($leave->start_date)->startOfDay();
                $leaveEnd = CarbonImmutable::instance($leave->end_date)->startOfDay();
                $unpaidDays += EmployeeLeaveService::businessDays(
                    $leaveStart->greaterThan($from) ? $leaveStart : $from,
                    $leaveEnd->lessThan($to) ? $leaveEnd : $to,
                );
            }
        }

        // Avances approuvées au plus tard ce mois-ci et pas encore retenues sur
        // une paie validée.
        $advances = HrRequest::query()
            ->where('employee_id', $employee->id)
            ->where('type', HrRequestType::SALARY_ADVANCE->value)
            ->where('status', HrWorkflowStatus::APPROVED->value)
            ->whereNull('payslip_id')
            ->where('decided_at', '<=', $end->endOfDay())
            ->orderBy('decided_at')
            ->get()
            ->map(fn (HrRequest $request) => ['id' => $request->id, 'label' => $request->subject, 'amount' => (int) $request->amount])
            ->all();

        $warnings = [];
        if ($employee->salary === null) {
            $warnings[] = ['code' => 'MISSING_SALARY', 'message' => 'Salaire non renseigné : établissez un avenant fixant la rémunération.', 'blocking' => true];
        }
        if ($employee->status === EmployeeStatus::SUSPENDED) {
            $warnings[] = ['code' => 'SUSPENDED', 'message' => 'Employé suspendu : vérifiez que la rémunération est due.', 'blocking' => false];
        }

        return [
            'baseSalary' => (int) ($employee->salary ?? 0),
            'businessDays' => $businessDays,
            'employedDays' => $employedDays,
            'unpaidLeaveDays' => min($unpaidDays, $employedDays),
            'advances' => $advances,
            'warnings' => $warnings,
        ];
    }

    /**
     * Identité recopiée sur le bulletin : il reste lisible et fidèle à la
     * situation du mois, même si la fiche change ou disparaît.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Employee $employee): array
    {
        return [
            'fullName' => $employee->full_name,
            'firstName' => $employee->first_name,
            'lastName' => $employee->last_name,
            'matricule' => $employee->matricule,
            'jobTitle' => $employee->job_title,
            'department' => $employee->department,
            'contractType' => $employee->contract_type?->value,
            'contractReference' => $employee->activeContract?->reference,
            'hireDate' => $employee->hire_date?->toDateString(),
        ];
    }

    private function refreshTotals(PayrollRun $run): void
    {
        $totals = $run->payslips()
            ->selectRaw('COUNT(*) as employees, COALESCE(SUM(gross), 0) as gross, COALESCE(SUM(employee_contributions), 0) as employee_contributions, COALESCE(SUM(employer_contributions), 0) as employer_contributions, COALESCE(SUM(tax), 0) as tax, COALESCE(SUM(net), 0) as net, COALESCE(SUM(employer_cost), 0) as employer_cost')
            ->toBase()
            ->first();

        $run->update([
            'employees_count' => (int) $totals->employees,
            'total_gross' => (int) $totals->gross,
            'total_employee_contributions' => (int) $totals->employee_contributions,
            'total_employer_contributions' => (int) $totals->employer_contributions,
            'total_tax' => (int) $totals->tax,
            'total_net' => (int) $totals->net,
            'total_employer_cost' => (int) $totals->employer_cost,
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function periodBounds(PayrollRun $run): array
    {
        $start = CarbonImmutable::create($run->year, $run->month, 1)->startOfDay();

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    private function laterRunExists(string $companyId, int $year, int $month): bool
    {
        return PayrollRun::where('company_id', $companyId)
            ->where(fn (Builder $q) => $q->where('year', '>', $year)->orWhere(fn (Builder $w) => $w->where('year', $year)->where('month', '>', $month)))
            ->exists();
    }

    private function assertDraft(PayrollRun $run, string $message = 'Cette paie est validée : rouvrez-la pour la modifier.'): void
    {
        if ($run->status !== PayrollRunStatus::DRAFT) {
            abort(400, $message);
        }
    }

    private function sortPayslips(PayrollRun $run): PayrollRun
    {
        return $run->setRelation('payslips', $run->payslips->sortBy(
            fn (Payslip $p) => mb_strtolower(($p->employee_snapshot['lastName'] ?? '').' '.($p->employee_snapshot['firstName'] ?? ''))
        )->values());
    }

    private function reloadRun(PayrollRun $run): PayrollRun
    {
        return $this->sortPayslips(PayrollRun::with('payslips')->findOrFail($run->id));
    }
}
