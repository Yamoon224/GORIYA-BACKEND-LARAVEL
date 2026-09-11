<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Employees', description: "Services RH — répertoire des employés de l'entreprise")]
class EmployeesController extends Controller
{
    use ResolvesEnterpriseCompany;

    public function __construct(private readonly EmployeeService $employees) {}

    #[OA\Get(
        path: '/employees',
        tags: ['Employees'],
        summary: "Employés de l'entreprise connectée",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'PROBATION', 'SUSPENDED', 'TERMINATED'])),
            new OA\Parameter(name: 'department', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Employés', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Employee'))),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function index(Request $request)
    {
        $companyId = $this->enterpriseCompanyId($request);

        return EmployeeResource::collection($this->employees->list($companyId, [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'department' => $request->query('department'),
        ]));
    }

    #[OA\Get(
        path: '/employees/hireable-candidatures',
        tags: ['Employees'],
        summary: 'Candidatures acceptées pas encore embauchées, avec la fiche employé pré-remplie',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Candidatures embauchables'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function hireableCandidatures(Request $request)
    {
        $companyId = $this->enterpriseCompanyId($request);

        return response()->json($this->employees->hireableCandidatures($companyId));
    }

    #[OA\Post(
        path: '/employees',
        tags: ['Employees'],
        summary: 'Ajoute un employé (saisie manuelle ou embauche d\'une candidature)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SaveEmployeeRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Employé créé', content: new OA\JsonContent(ref: '#/components/schemas/Employee')),
            new OA\Response(response: 400, description: 'Validation échouée ou candidature non embauchable'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function store(SaveEmployeeRequest $request)
    {
        $companyId = $this->enterpriseCompanyId($request);
        $employee = $this->employees->create($companyId, $request->validated(), $request->user());

        return (new EmployeeResource($employee))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/employees/{id}',
        tags: ['Employees'],
        summary: "Fiche d'un employé, avec son solde de congés de l'année",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Employé', content: new OA\JsonContent(ref: '#/components/schemas/Employee')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        $employee = $this->findOrFail($id, $request);

        return (new EmployeeResource($employee))->withLeaveBalance($this->employees->leaveBalance($employee));
    }

    #[OA\Patch(
        path: '/employees/{id}',
        tags: ['Employees'],
        summary: "Modifie la fiche d'un employé",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SaveEmployeeRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Employé modifié', content: new OA\JsonContent(ref: '#/components/schemas/Employee')),
            new OA\Response(response: 400, description: 'Validation échouée'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function update(string $id, SaveEmployeeRequest $request)
    {
        $employee = $this->employees->update($this->findOrFail($id, $request), $request->validated());

        return (new EmployeeResource($employee))->withLeaveBalance($this->employees->leaveBalance($employee));
    }

    #[OA\Delete(
        path: '/employees/{id}',
        tags: ['Employees'],
        summary: 'Supprime un employé, ses congés et ses demandes',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Employé supprimé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $this->employees->delete($this->findOrFail($id, $request));

        return response()->json(['message' => 'Employé supprimé']);
    }

    private function findOrFail(string $id, Request $request): Employee
    {
        $employee = $this->employees->find($id, $this->enterpriseCompanyId($request));

        if (! $employee) {
            abort(404, 'Employé introuvable.');
        }

        return $employee;
    }
}
