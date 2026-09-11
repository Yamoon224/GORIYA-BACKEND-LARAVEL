<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PayrollRun',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer'),
        new OA\Property(property: 'label', type: 'string', example: 'septembre 2026'),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'VALIDATED', 'PAID']),
        new OA\Property(property: 'employeesCount', type: 'integer'),
        new OA\Property(property: 'totals', type: 'object', properties: [
            new OA\Property(property: 'gross', type: 'integer'),
            new OA\Property(property: 'employeeContributions', type: 'integer'),
            new OA\Property(property: 'employerContributions', type: 'integer'),
            new OA\Property(property: 'tax', type: 'integer'),
            new OA\Property(property: 'net', type: 'integer'),
            new OA\Property(property: 'employerCost', type: 'integer'),
        ]),
        new OA\Property(property: 'paymentDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'paymentReference', type: 'string', nullable: true),
        new OA\Property(property: 'validatedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'paidAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'payslips', type: 'array', nullable: true, description: 'Détail uniquement', items: new OA\Items(ref: '#/components/schemas/Payslip')),
    ]
)]
class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'label' => CarbonImmutable::create($this->year, $this->month, 1)->locale('fr')->isoFormat('MMMM YYYY'),
            'status' => $this->status?->value,
            'employeesCount' => $this->employees_count,
            'totals' => [
                'gross' => $this->total_gross,
                'employeeContributions' => $this->total_employee_contributions,
                'employerContributions' => $this->total_employer_contributions,
                'tax' => $this->total_tax,
                'net' => $this->total_net,
                'employerCost' => $this->total_employer_cost,
            ],
            'paymentDate' => $this->payment_date?->toDateString(),
            'paymentReference' => $this->payment_reference,
            'validatedAt' => $this->validated_at,
            'paidAt' => $this->paid_at,
            'createdAt' => $this->created_at,
            'payslips' => $this->whenLoaded('payslips', fn () => PayslipResource::collection($this->payslips)->resolve($request)),
        ];
    }
}
