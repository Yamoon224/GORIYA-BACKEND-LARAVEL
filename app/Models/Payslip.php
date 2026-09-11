<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bulletin de paie d'un employé pour une période. Les montants et le détail des
 * lignes sont calculés par PayrollCalculator ; `bonuses` et `deductions` sont
 * les seules saisies manuelles.
 */
class Payslip extends Model
{
    use Auditable, HasUuid;

    /** Le détail recalculé ferait gonfler le journal d'audit à chaque recalcul. */
    protected array $auditExcludes = ['lines', 'employee_snapshot', 'warnings'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'payroll_run_id',
        'employee_id',
        'employee_snapshot',
        'base_salary',
        'business_days',
        'worked_days',
        'unpaid_leave_days',
        'bonuses',
        'deductions',
        'advance_ids',
        'lines',
        'warnings',
        'gross',
        'employee_contributions',
        'employer_contributions',
        'taxable',
        'tax',
        'advances',
        'other_deductions',
        'net',
        'employer_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_snapshot' => 'array',
            'bonuses' => 'array',
            'deductions' => 'array',
            'advance_ids' => 'array',
            'lines' => 'array',
            'warnings' => 'array',
            'base_salary' => 'integer',
            'business_days' => 'integer',
            'worked_days' => 'integer',
            'unpaid_leave_days' => 'integer',
            'gross' => 'integer',
            'employee_contributions' => 'integer',
            'employer_contributions' => 'integer',
            'taxable' => 'integer',
            'tax' => 'integer',
            'advances' => 'integer',
            'other_deductions' => 'integer',
            'net' => 'integer',
            'employer_cost' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
