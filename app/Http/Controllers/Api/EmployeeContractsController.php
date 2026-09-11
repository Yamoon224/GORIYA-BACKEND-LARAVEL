<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContractStatus;
use App\Enums\JobType;
use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Concerns\SplitsListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEmployeeContractRequest;
use App\Http\Requests\UpdateContractStatusRequest;
use App\Http\Resources\EmployeeContractResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Services\EmployeeContractDocumentService;
use App\Services\EmployeeContractService;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Employee Contracts', description: 'Services RH — contrats de travail des employés')]
class EmployeeContractsController extends Controller
{
    use ResolvesEnterpriseCompany, SplitsListQuery;

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EmployeeContractService $contracts,
        private readonly EmployeeContractDocumentService $documents,
    ) {}

    #[OA\Get(
        path: '/employee-contracts',
        tags: ['Employee Contracts'],
        summary: "Contrats de toute l'entreprise, avec l'employé concerné",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Un ou plusieurs statuts, séparés par des virgules', schema: new OA\Schema(type: 'string', example: 'ACTIVE,DRAFT')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'CDD')),
            new OA\Parameter(name: 'employeeId', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Référence, nom ou matricule', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contrats', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EmployeeContract'))),
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
            'status.*' => [Rule::enum(ContractStatus::class)],
            'type' => ['nullable', 'array'],
            'type.*' => [Rule::enum(JobType::class)],
            'employeeId' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return EmployeeContractResource::collection($this->contracts->listForCompany($companyId, $filters));
    }

    #[OA\Get(
        path: '/employees/{employeeId}/contracts',
        tags: ['Employee Contracts'],
        summary: "Contrats d'un employé, du plus récent au plus ancien",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Contrats', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EmployeeContract'))),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function index(string $employeeId, Request $request)
    {
        return EmployeeContractResource::collection($this->contracts->listFor($this->employeeOrFail($employeeId, $request)));
    }

    #[OA\Post(
        path: '/employees/{employeeId}/contracts',
        tags: ['Employee Contracts'],
        summary: 'Établit un contrat, un renouvellement ou un avenant (brouillon ou en vigueur)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SaveEmployeeContractRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Contrat créé', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeContract')),
            new OA\Response(response: 400, description: 'Conditions incohérentes ou contrat d\'origine invalide'),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function store(string $employeeId, SaveEmployeeContractRequest $request)
    {
        $contract = $this->contracts->create($this->employeeOrFail($employeeId, $request), $request->user(), $request->validated());

        return (new EmployeeContractResource($contract))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/employee-contracts/{id}',
        tags: ['Employee Contracts'],
        summary: 'Modifie un brouillon, ou la signature et les notes d\'un contrat en vigueur',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SaveEmployeeContractRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Contrat modifié', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeContract')),
            new OA\Response(response: 400, description: 'Contrat verrouillé ou conditions incohérentes'),
            new OA\Response(response: 404, description: 'Contrat introuvable'),
        ]
    )]
    public function update(string $id, SaveEmployeeContractRequest $request)
    {
        $contract = $this->contracts->update($this->contractOrFail($id, $request), $request->validated());

        return new EmployeeContractResource($contract);
    }

    #[OA\Patch(
        path: '/employee-contracts/{id}/status',
        tags: ['Employee Contracts'],
        summary: 'Met en vigueur, clôture ou rompt un contrat',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateContractStatusRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Contrat mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeContract')),
            new OA\Response(response: 400, description: 'Transition interdite ou date incohérente'),
            new OA\Response(response: 404, description: 'Contrat introuvable'),
        ]
    )]
    public function updateStatus(string $id, UpdateContractStatusRequest $request)
    {
        $data = $request->validated();
        $contract = $this->contracts->changeStatus(
            $this->contractOrFail($id, $request),
            ContractStatus::from($data['status']),
            $data,
        );

        return new EmployeeContractResource($contract);
    }

    #[OA\Delete(
        path: '/employee-contracts/{id}',
        tags: ['Employee Contracts'],
        summary: 'Supprime un brouillon de contrat',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Brouillon supprimé'),
            new OA\Response(response: 400, description: 'Contrat déjà mis en vigueur'),
            new OA\Response(response: 404, description: 'Contrat introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $this->contracts->delete($this->contractOrFail($id, $request));

        return response()->json(['message' => 'Contrat supprimé']);
    }

    #[OA\Post(
        path: '/employee-contracts/{id}/document',
        tags: ['Employee Contracts'],
        summary: 'Joint le contrat signé (PDF ou image, 10 Mo max.)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(required: ['document'], properties: [new OA\Property(property: 'document', type: 'string', format: 'binary')])
        )),
        responses: [
            new OA\Response(response: 200, description: 'Document joint', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeContract')),
            new OA\Response(response: 400, description: 'Fichier refusé'),
            new OA\Response(response: 404, description: 'Contrat introuvable'),
        ]
    )]
    public function uploadDocument(string $id, Request $request)
    {
        $contract = $this->contractOrFail($id, $request);
        $data = $request->validate(
            ['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']],
            [
                'document.mimes' => 'Le contrat signé doit être un PDF ou une image (JPG, PNG).',
                'document.max' => 'Le document ne doit pas dépasser 10 Mo.',
            ],
        );

        return new EmployeeContractResource($this->contracts->attachDocument($contract, $data['document']));
    }

    #[OA\Get(
        path: '/employee-contracts/{id}/document',
        tags: ['Employee Contracts'],
        summary: 'Télécharge le contrat signé',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fichier'),
            new OA\Response(response: 404, description: 'Contrat ou document introuvable'),
        ]
    )]
    public function downloadDocument(string $id, Request $request)
    {
        $contract = $this->contractOrFail($id, $request);
        if (! $this->contracts->hasStoredDocument($contract)) {
            abort(404, 'Aucun document joint à ce contrat.');
        }

        return Storage::disk('local')->download($contract->document_path, $contract->document_name);
    }

    #[OA\Delete(
        path: '/employee-contracts/{id}/document',
        tags: ['Employee Contracts'],
        summary: 'Retire le document joint',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Document retiré', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeContract')),
            new OA\Response(response: 404, description: 'Contrat introuvable'),
        ]
    )]
    public function deleteDocument(string $id, Request $request)
    {
        return new EmployeeContractResource($this->contracts->removeDocument($this->contractOrFail($id, $request)));
    }

    #[OA\Get(
        path: '/employee-contracts/{id}/draft',
        tags: ['Employee Contracts'],
        summary: 'Génère un projet de contrat .docx à partir des informations saisies',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fichier .docx', content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')),
            new OA\Response(response: 404, description: 'Contrat introuvable'),
        ]
    )]
    public function draft(string $id, Request $request)
    {
        $contract = $this->contractOrFail($id, $request);
        $path = $this->documents->generate($contract);

        return response()->download($path, "projet-{$contract->reference}.docx")->deleteFileAfterSend(true);
    }

    private function employeeOrFail(string $employeeId, Request $request): Employee
    {
        $employee = $this->employees->find($employeeId, $this->enterpriseCompanyId($request));
        if (! $employee) {
            abort(404, 'Employé introuvable.');
        }

        return $employee;
    }

    private function contractOrFail(string $id, Request $request): EmployeeContract
    {
        $contract = $this->contracts->find($id, $this->enterpriseCompanyId($request));
        if (! $contract) {
            abort(404, 'Contrat introuvable.');
        }

        return $contract;
    }
}
