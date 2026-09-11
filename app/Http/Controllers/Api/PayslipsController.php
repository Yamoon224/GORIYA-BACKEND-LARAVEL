<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePayslipRequest;
use App\Http\Resources\PayslipResource;
use App\Models\Payslip;
use App\Services\EmployeeService;
use App\Services\PayrollService;
use App\Services\PayslipDocumentService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PayslipsController extends Controller
{
    use ResolvesEnterpriseCompany;

    public function __construct(
        private readonly PayrollService $payroll,
        private readonly EmployeeService $employees,
        private readonly PayslipDocumentService $documents,
    ) {}

    #[OA\Get(
        path: '/employees/{employeeId}/payslips',
        tags: ['Payroll'],
        summary: "Bulletins d'un employé, du plus récent au plus ancien",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Bulletins', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Payslip'))),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function employeeIndex(string $employeeId, Request $request)
    {
        $employee = $this->employees->find($employeeId, $this->enterpriseCompanyId($request));
        if (! $employee) {
            abort(404, 'Employé introuvable.');
        }

        return PayslipResource::collection($this->payroll->payslipsOf($employee));
    }

    #[OA\Get(
        path: '/payslips/{id}',
        tags: ['Payroll'],
        summary: 'Bulletin de paie',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Bulletin', content: new OA\JsonContent(ref: '#/components/schemas/Payslip')),
            new OA\Response(response: 404, description: 'Bulletin introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        return new PayslipResource($this->payslipOrFail($id, $request));
    }

    #[OA\Patch(
        path: '/payslips/{id}',
        tags: ['Payroll'],
        summary: 'Remplace les primes et retenues d\'un bulletin en brouillon, puis le recalcule',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdatePayslipRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Bulletin recalculé', content: new OA\JsonContent(ref: '#/components/schemas/Payslip')),
            new OA\Response(response: 400, description: 'Paie validée ou saisie invalide'),
        ]
    )]
    public function update(string $id, UpdatePayslipRequest $request)
    {
        return new PayslipResource($this->payroll->updateAdjustments($this->payslipOrFail($id, $request), $request->validated()));
    }

    #[OA\Get(
        path: '/payslips/{id}/document',
        tags: ['Payroll'],
        summary: 'Bulletin de paie au format .docx',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Fichier .docx')]
    )]
    public function document(string $id, Request $request)
    {
        $payslip = $this->payslipOrFail($id, $request);
        $filename = sprintf(
            'bulletin-%04d-%02d-%s.docx',
            $payslip->run->year,
            $payslip->run->month,
            $payslip->employee_snapshot['matricule'] ?? 'employe',
        );

        return response()->download($this->documents->generate($payslip), $filename)->deleteFileAfterSend(true);
    }

    private function payslipOrFail(string $id, Request $request): Payslip
    {
        $payslip = $this->payroll->findPayslip($id, $this->enterpriseCompanyId($request));
        if (! $payslip) {
            abort(404, 'Bulletin introuvable.');
        }

        return $payslip;
    }
}
