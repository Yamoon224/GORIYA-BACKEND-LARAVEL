<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Payslip',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payrollRunId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employeeId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'employee', type: 'object', description: 'Identité recopiée au calcul'),
        new OA\Property(property: 'run', type: 'object', nullable: true),
        new OA\Property(property: 'baseSalary', type: 'integer'),
        new OA\Property(property: 'businessDays', type: 'integer'),
        new OA\Property(property: 'workedDays', type: 'integer'),
        new OA\Property(property: 'unpaidLeaveDays', type: 'integer'),
        new OA\Property(property: 'bonuses', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'deductions', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'gross', type: 'integer'),
        new OA\Property(property: 'employeeContributions', type: 'integer'),
        new OA\Property(property: 'employerContributions', type: 'integer'),
        new OA\Property(property: 'taxable', type: 'integer'),
        new OA\Property(property: 'tax', type: 'integer'),
        new OA\Property(property: 'advances', type: 'integer'),
        new OA\Property(property: 'otherDeductions', type: 'integer'),
        new OA\Property(property: 'net', type: 'integer'),
        new OA\Property(property: 'employerCost', type: 'integer'),
    ]
)]
class PayslipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payrollRunId' => $this->payroll_run_id,
            'employeeId' => $this->employee_id,
            'employee' => $this->employee_snapshot,
            'run' => $this->whenLoaded('run', fn () => $this->run ? [
                'id' => $this->run->id,
                'year' => $this->run->year,
                'month' => $this->run->month,
                'label' => CarbonImmutable::create($this->run->year, $this->run->month, 1)->locale('fr')->isoFormat('MMMM YYYY'),
                'status' => $this->run->status?->value,
                'paymentDate' => $this->run->payment_date?->toDateString(),
            ] : null),
            'baseSalary' => $this->base_salary,
            'businessDays' => $this->business_days,
            'workedDays' => $this->worked_days,
            'unpaidLeaveDays' => $this->unpaid_leave_days,
            'bonuses' => $this->bonuses ?? [],
            'deductions' => $this->deductions ?? [],
            'lines' => $this->lines ?? [],
            'warnings' => $this->warnings ?? [],
            'gross' => $this->gross,
            'employeeContributions' => $this->employee_contributions,
            'employerContributions' => $this->employer_contributions,
            'taxable' => $this->taxable,
            'tax' => $this->tax,
            'advances' => $this->advances,
            'otherDeductions' => $this->other_deductions,
            'net' => $this->net,
            'employerCost' => $this->employer_cost,
            'updatedAt' => $this->updated_at,
        ];
    }
}
