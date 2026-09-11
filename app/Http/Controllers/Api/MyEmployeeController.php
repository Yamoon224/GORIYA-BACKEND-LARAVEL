<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEmployeeLeaveRequest;
use App\Http\Requests\CreateHrRequestRequest;
use App\Http\Resources\EmployeeLeaveResource;
use App\Http\Resources\HrRequestResource;
use App\Http\Resources\EmployeeSurveyResource;
use App\Http\Resources\MyEmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\HrRequest;
use App\Services\EmployeeLeaveService;
use App\Services\EmployeeService;
use App\Services\EmployeeSurveyService;
use App\Services\HrRequestService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Espace employé (standard/app/(protected)/espace-employe) : contrairement au
 * reste des Services RH (réservés au compte ENTREPRISE, voir
 * ResolvesEnterpriseCompany), ces routes servent le compte USER qui EST cet
 * employé — résolu depuis `Employee.user_id`, jamais depuis un identifiant du
 * path, pour qu'un employé ne puisse jamais lire la fiche d'un autre.
 *
 * Un compte n'a jamais été employé : `show()` répond `null`, les autres
 * endpoints 404. Un compte dont la fiche est TERMINATED garde l'accès en
 * lecture (historique) mais ne peut plus créer de nouvelle demande — voir
 * `assertActive()`.
 */
#[OA\Tag(name: 'My Employee', description: "Espace employé — accessible à tout utilisateur ayant été embauché sur Goriya")]
class MyEmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EmployeeLeaveService $leaves,
        private readonly HrRequestService $hrRequests,
        private readonly EmployeeSurveyService $surveys,
    ) {}

    #[OA\Get(
        path: '/me/employee',
        tags: ['My Employee'],
        summary: "Fiche employé la plus récente de l'utilisateur connecté, tous employeurs confondus",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: "Fiche employé, ou corps JSON `null` si l'utilisateur n'a jamais été employé sur Goriya", content: new OA\JsonContent(ref: '#/components/schemas/MyEmployee')),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function show(Request $request)
    {
        $employee = $this->myEmployee($request);
        if (! $employee) {
            return response('null', 200)->header('Content-Type', 'application/json');
        }

        $employee->loadMissing('company');

        return (new MyEmployeeResource($employee))->withLeaveBalance($this->employees->leaveBalance($employee));
    }

    #[OA\Get(
        path: '/me/employee/leaves',
        tags: ['My Employee'],
        summary: 'Mes congés, du plus récent au plus ancien',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Congés', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EmployeeLeave'))),
            new OA\Response(response: 404, description: "Vous n'avez jamais été employé sur Goriya"),
        ]
    )]
    public function leaves(Request $request)
    {
        return EmployeeLeaveResource::collection($this->leaves->listFor($this->myEmployeeOrFail($request)));
    }

    #[OA\Post(
        path: '/me/employee/leaves',
        tags: ['My Employee'],
        summary: 'Pose un congé (statut PENDING)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateEmployeeLeaveRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Congé créé', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeLeave')),
            new OA\Response(response: 400, description: 'Période invalide, chevauchement ou fiche inactive'),
            new OA\Response(response: 404, description: "Vous n'avez jamais été employé sur Goriya"),
        ]
    )]
    public function storeLeave(CreateEmployeeLeaveRequest $request)
    {
        $employee = $this->assertActive($this->myEmployeeOrFail($request));
        $leave = $this->leaves->create($employee, $request->user(), $request->validated());

        return (new EmployeeLeaveResource($leave))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/me/employee/leaves/{id}',
        tags: ['My Employee'],
        summary: 'Retire une de mes demandes de congé non approuvée',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Congé supprimé'),
            new OA\Response(response: 400, description: 'Congé déjà approuvé : à annuler, pas à supprimer'),
            new OA\Response(response: 404, description: 'Congé introuvable'),
        ]
    )]
    public function destroyLeave(string $id, Request $request)
    {
        $this->leaves->delete($this->myLeaveOrFail($id, $request));

        return response()->json(['message' => 'Congé supprimé']);
    }

    #[OA\Get(
        path: '/me/employee/hr-requests',
        tags: ['My Employee'],
        summary: 'Mes demandes RH (attestations, avances, formations…)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Demandes', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/HrRequest'))),
            new OA\Response(response: 404, description: "Vous n'avez jamais été employé sur Goriya"),
        ]
    )]
    public function hrRequests(Request $request)
    {
        return HrRequestResource::collection($this->hrRequests->listFor($this->myEmployeeOrFail($request)));
    }

    #[OA\Post(
        path: '/me/employee/hr-requests',
        tags: ['My Employee'],
        summary: 'Enregistre une demande RH (statut PENDING)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateHrRequestRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Demande créée', content: new OA\JsonContent(ref: '#/components/schemas/HrRequest')),
            new OA\Response(response: 400, description: 'Validation échouée ou fiche inactive'),
            new OA\Response(response: 404, description: "Vous n'avez jamais été employé sur Goriya"),
        ]
    )]
    public function storeHrRequest(CreateHrRequestRequest $request)
    {
        $employee = $this->assertActive($this->myEmployeeOrFail($request));
        $hrRequest = $this->hrRequests->create($employee, $request->user(), $request->validated());

        return (new HrRequestResource($hrRequest))->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/me/employee/hr-requests/{id}',
        tags: ['My Employee'],
        summary: "Retire une de mes demandes RH non prise en charge",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Demande supprimée'),
            new OA\Response(response: 400, description: 'Demande déjà approuvée'),
            new OA\Response(response: 404, description: 'Demande introuvable'),
        ]
    )]
    public function destroyHrRequest(string $id, Request $request)
    {
        $this->hrRequests->delete($this->myHrRequestOrFail($id, $request));

        return response()->json(['message' => 'Demande supprimée']);
    }

    #[OA\Get(
        path: '/me/employee/evaluations',
        tags: ['My Employee'],
        summary: "Évaluations actives visibles par l'employé (toute l'entreprise, ou son département si ciblée)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Évaluations', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EmployeeSurvey'))),
            new OA\Response(response: 404, description: "Vous n'avez jamais été employé sur Goriya"),
        ]
    )]
    public function evaluations(Request $request)
    {
        return EmployeeSurveyResource::collection($this->surveys->listForEmployee($this->myEmployeeOrFail($request)));
    }

    private function myEmployee(Request $request): ?Employee
    {
        return $this->employees->findByUser((string) $request->user()->id);
    }

    private function myEmployeeOrFail(Request $request): Employee
    {
        $employee = $this->myEmployee($request);
        if (! $employee) {
            abort(404, "Vous n'avez jamais été employé sur Goriya.");
        }

        return $employee;
    }

    /** Une fiche TERMINATED reste lisible (historique) mais ferme les nouvelles demandes. */
    private function assertActive(Employee $employee): Employee
    {
        if ($employee->status === EmployeeStatus::TERMINATED) {
            abort(400, "Vous n'êtes plus employé·e de cette entreprise : vous ne pouvez plus soumettre de nouvelle demande.");
        }

        return $employee;
    }

    private function myLeaveOrFail(string $id, Request $request): EmployeeLeave
    {
        $employee = $this->myEmployeeOrFail($request);
        $leave = $this->leaves->find($id, $employee->company_id);
        if (! $leave || $leave->employee_id !== $employee->id) {
            abort(404, 'Congé introuvable.');
        }

        return $leave;
    }

    private function myHrRequestOrFail(string $id, Request $request): HrRequest
    {
        $employee = $this->myEmployeeOrFail($request);
        $hrRequest = $this->hrRequests->find($id, $employee->company_id);
        if (! $hrRequest || $hrRequest->employee_id !== $employee->id) {
            abort(404, 'Demande introuvable.');
        }

        return $hrRequest;
    }
}
