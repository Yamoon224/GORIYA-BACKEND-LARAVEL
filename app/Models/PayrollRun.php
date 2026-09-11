<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Période de paie mensuelle d'une entreprise — règles dans PayrollService. */
class PayrollRun extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'year',
        'month',
        'status',
        'employees_count',
        'total_gross',
        'total_employee_contributions',
        'total_employer_contributions',
        'total_tax',
        'total_net',
        'total_employer_cost',
        'payment_date',
        'payment_reference',
        'created_by',
        'validated_by',
        'validated_at',
        'paid_by',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class,
            'year' => 'integer',
            'month' => 'integer',
            'employees_count' => 'integer',
            'total_gross' => 'integer',
            'total_employee_contributions' => 'integer',
            'total_employer_contributions' => 'integer',
            'total_tax' => 'integer',
            'total_net' => 'integer',
            'total_employer_cost' => 'integer',
            'payment_date' => 'date',
            'validated_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
