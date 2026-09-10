<?php

namespace App\Http\Controllers\Api;

use App\Enums\HrWorkflowStatus;
use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEmployeeLeaveRequest;
use App\Http\Requests\UpdateHrWorkflowStatusRequest;
use App\Http\Resources\EmployeeLeaveResource;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Services\EmployeeLeaveService;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Employee Leaves', description: "Services RH — congés des employés")]
class EmployeeLeavesController extends Controller
{
    use ResolvesEnterpriseCompany;

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EmployeeLeaveService $leaves,
    ) {}

    #[OA\Get(
        path: '/employees/{employeeId}/leaves',
        tags: ['Employee Leaves'],
        summary: "Congés d'un employé, du plus récent au plus ancien",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Congés', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EmployeeLeave'))),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function index(string $employeeId, Request $request)
    {
        return EmployeeLeaveResource::collection($this->leaves->listFor($this->employeeOrFail($employeeId, $request)));
    }

    #[OA\Post(
        path: '/employees/{employeeId}/leaves',
        tags: ['Employee Leaves'],
        summary: 'Pose un congé (statut PENDING)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateEmployeeLeaveRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Congé créé', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeLeave')),
            new OA\Response(response: 400, description: 'Période invalide, chevauchement ou employé parti'),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function store(string $employeeId, CreateEmployeeLeaveRequest $request)
    {
        $leave = $this->leaves->create($this->employeeOrFail($employeeId, $request), $request->user(), $request->validated());

        return (new EmployeeLeaveResource($leave))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/employee-leaves/{id}/status',
        tags: ['Employee Leaves'],
        summary: 'Approuve, refuse ou annule un congé',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateHrWorkflowStatusRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Congé mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeLeave')),
            new OA\Response(response: 400, description: 'Transition interdite ou solde insuffisant'),
            new OA\Response(response: 404, description: 'Congé introuvable'),
        ]
    )]
    public function updateStatus(string $id, UpdateHrWorkflowStatusRequest $request)
    {
        $data = $request->validated();
        $leave = $this->leaves->decide(
            $this->leaveOrFail($id, $request),
            $request->user(),
            HrWorkflowStatus::from($data['status']),
            $data['comment'] ?? null,
        );

        return new EmployeeLeaveResource($leave);
    }

    #[OA\Delete(
        path: '/employee-leaves/{id}',
        tags: ['Employee Leaves'],
        summary: 'Supprime un congé non approuvé',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Congé supprimé'),
            new OA\Response(response: 400, description: 'Congé approuvé : à annuler, pas à supprimer'),
            new OA\Response(response: 404, description: 'Congé introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $this->leaves->delete($this->leaveOrFail($id, $request));

        return response()->json(['message' => 'Congé supprimé']);
    }

    private function employeeOrFail(string $employeeId, Request $request): Employee
    {
        $employee = $this->employees->find($employeeId, $this->enterpriseCompanyId($request));
        if (! $employee) {
            abort(404, 'Employé introuvable.');
        }

        return $employee;
    }

    private function leaveOrFail(string $id, Request $request): EmployeeLeave
    {
        $leave = $this->leaves->find($id, $this->enterpriseCompanyId($request));
        if (! $leave) {
            abort(404, 'Congé introuvable.');
        }

        return $leave;
    }
}
