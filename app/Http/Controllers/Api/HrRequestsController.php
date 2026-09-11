<?php

namespace App\Http\Controllers\Api;

use App\Enums\HrRequestType;
use App\Enums\HrWorkflowStatus;
use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Concerns\SplitsListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateHrRequestRequest;
use App\Http\Requests\UpdateHrWorkflowStatusRequest;
use App\Http\Resources\HrRequestResource;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Services\EmployeeService;
use App\Services\HrRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'HR Requests', description: "Services RH — demandes des employés (attestations, avances…)")]
class HrRequestsController extends Controller
{
    use ResolvesEnterpriseCompany, SplitsListQuery;

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly HrRequestService $hrRequests,
    ) {}

    #[OA\Get(
        path: '/hr-requests',
        tags: ['HR Requests'],
        summary: "Demandes RH de toute l'entreprise, avec l'employé concerné",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Un ou plusieurs statuts, séparés par des virgules', schema: new OA\Schema(type: 'string', example: 'PENDING,IN_PROGRESS')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Un ou plusieurs types, séparés par des virgules', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'employeeId', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'department', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Demandes', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/HrRequest'))),
            new OA\Response(response: 400, description: 'Filtre invalide'),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function companyIndex(Request $request)
    {
        $companyId = $this->enterpriseCompanyId($request);

        $this->splitListQuery($request, ['status', 'type']);

        $filters = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(HrWorkflowStatus::class)],
            'type' => ['nullable', 'array'],
            'type.*' => [Rule::enum(HrRequestType::class)],
            'employeeId' => ['nullable', 'uuid'],
            'department' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return HrRequestResource::collection($this->hrRequests->listForCompany($companyId, $filters));
    }

    #[OA\Get(
        path: '/employees/{employeeId}/hr-requests',
        tags: ['HR Requests'],
        summary: "Demandes RH d'un employé",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Demandes', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/HrRequest'))),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function index(string $employeeId, Request $request)
    {
        return HrRequestResource::collection($this->hrRequests->listFor($this->employeeOrFail($employeeId, $request)));
    }

    #[OA\Post(
        path: '/employees/{employeeId}/hr-requests',
        tags: ['HR Requests'],
        summary: 'Enregistre une demande RH (statut PENDING)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateHrRequestRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Demande créée', content: new OA\JsonContent(ref: '#/components/schemas/HrRequest')),
            new OA\Response(response: 400, description: 'Validation échouée'),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function store(string $employeeId, CreateHrRequestRequest $request)
    {
        $hrRequest = $this->hrRequests->create($this->employeeOrFail($employeeId, $request), $request->user(), $request->validated());

        return (new HrRequestResource($hrRequest))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/hr-requests/{id}/status',
        tags: ['HR Requests'],
        summary: 'Prend en charge, approuve, refuse ou annule une demande',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateHrWorkflowStatusRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Demande mise à jour', content: new OA\JsonContent(ref: '#/components/schemas/HrRequest')),
            new OA\Response(response: 400, description: 'Transition interdite'),
            new OA\Response(response: 404, description: 'Demande introuvable'),
        ]
    )]
    public function updateStatus(string $id, UpdateHrWorkflowStatusRequest $request)
    {
        $data = $request->validated();
        $hrRequest = $this->hrRequests->decide(
            $this->hrRequestOrFail($id, $request),
            $request->user(),
            HrWorkflowStatus::from($data['status']),
            $data['comment'] ?? null,
        );

        return new HrRequestResource($hrRequest);
    }

    #[OA\Delete(
        path: '/hr-requests/{id}',
        tags: ['HR Requests'],
        summary: 'Supprime une demande non approuvée',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Demande supprimée'),
            new OA\Response(response: 400, description: 'Demande approuvée'),
            new OA\Response(response: 404, description: 'Demande introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $this->hrRequests->delete($this->hrRequestOrFail($id, $request));

        return response()->json(['message' => 'Demande supprimée']);
    }

    private function employeeOrFail(string $employeeId, Request $request): Employee
    {
        $employee = $this->employees->find($employeeId, $this->enterpriseCompanyId($request));
        if (! $employee) {
            abort(404, 'Employé introuvable.');
        }

        return $employee;
    }

    private function hrRequestOrFail(string $id, Request $request): HrRequest
    {
        $hrRequest = $this->hrRequests->find($id, $this->enterpriseCompanyId($request));
        if (! $hrRequest) {
            abort(404, 'Demande introuvable.');
        }

        return $hrRequest;
    }
}
