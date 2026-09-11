<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmployeeDocumentCategory;
use App\Enums\HrDocumentTemplate;
use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Concerns\SplitsListQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeDocumentResource;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\EmployeeDocumentService;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'HR Documents', description: 'Services RH — documents des employés et de l\'entreprise')]
class EmployeeDocumentsController extends Controller
{
    use ResolvesEnterpriseCompany, SplitsListQuery;

    /** Formats acceptés : documents bureautiques et scans. */
    private const MIMES = 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,odt,ods,txt';

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EmployeeDocumentService $documents,
    ) {}

    #[OA\Get(
        path: '/hr-documents',
        tags: ['HR Documents'],
        summary: "Documents RH de l'entreprise, contrats signés et bulletins validés compris",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'employeeId', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'scope', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['all', 'company', 'employees'])),
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Une ou plusieurs catégories, séparées par des virgules', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'expiringWithin', in: 'query', required: false, description: 'Documents expirés ou expirant sous N jours', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'includeLinked', in: 'query', required: false, description: 'Contrats signés et bulletins (défaut : 1)', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EmployeeDocument'))),
            new OA\Response(response: 400, description: 'Filtre invalide'),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function index(Request $request)
    {
        $companyId = $this->enterpriseCompanyId($request);
        $this->splitListQuery($request, ['category']);

        $filters = $request->validate([
            'employeeId' => ['nullable', 'uuid'],
            'scope' => ['nullable', Rule::in(['all', 'company', 'employees'])],
            'category' => ['nullable', 'array'],
            'category.*' => [Rule::enum(EmployeeDocumentCategory::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'expiringWithin' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'includeLinked' => ['nullable', 'boolean'],
        ]);

        if (! empty($filters['employeeId'])) {
            $this->employeeOrFail($filters['employeeId'], $request);
        }
        $filters['includeLinked'] = $request->has('includeLinked') ? $request->boolean('includeLinked') : true;

        return response()->json($this->documents->listForCompany($companyId, $filters));
    }

    #[OA\Post(
        path: '/hr-documents',
        tags: ['HR Documents'],
        summary: "Dépose un document, dans le dossier d'un employé ou au niveau de l'entreprise",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(required: ['file', 'category'], properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'category', type: 'string'),
                new OA\Property(property: 'employeeId', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'title', type: 'string', nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'issuedAt', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'expiresAt', type: 'string', format: 'date', nullable: true),
            ])
        )),
        responses: [
            new OA\Response(response: 201, description: 'Document déposé', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeDocument')),
            new OA\Response(response: 400, description: 'Fichier refusé ou dates incohérentes'),
            new OA\Response(response: 404, description: 'Employé introuvable'),
        ]
    )]
    public function store(Request $request)
    {
        $companyId = $this->enterpriseCompanyId($request);
        $data = $request->validate(
            array_merge($this->metadataRules(false), [
                'file' => ['required', 'file', 'mimes:'.self::MIMES, 'max:10240'],
            ]),
            $this->messages(),
        );

        $employee = empty($data['employeeId']) ? null : $this->employeeOrFail($data['employeeId'], $request);
        $document = $this->documents->upload($companyId, $employee, $request->user(), $data);

        return response()->json(EmployeeDocumentResource::fromDocument($document), 201);
    }

    #[OA\Patch(
        path: '/hr-documents/{id}',
        tags: ['HR Documents'],
        summary: "Modifie les informations d'un document (titre, catégorie, dates, dossier)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Document modifié', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeDocument')),
            new OA\Response(response: 400, description: 'Dates incohérentes'),
            new OA\Response(response: 404, description: 'Document introuvable'),
        ]
    )]
    public function update(string $id, Request $request)
    {
        $document = $this->documentOrFail($id, $request);
        $data = $request->validate($this->metadataRules(true), $this->messages());

        if (! empty($data['employeeId'])) {
            $this->employeeOrFail($data['employeeId'], $request);
        }

        return response()->json(EmployeeDocumentResource::fromDocument($this->documents->update($document, $data)));
    }

    #[OA\Delete(
        path: '/hr-documents/{id}',
        tags: ['HR Documents'],
        summary: 'Supprime un document et son fichier',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Document supprimé'),
            new OA\Response(response: 404, description: 'Document introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $this->documents->delete($this->documentOrFail($id, $request));

        return response()->json(['message' => 'Document supprimé']);
    }

    #[OA\Get(
        path: '/hr-documents/{id}/download',
        tags: ['HR Documents'],
        summary: 'Télécharge un document',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fichier'),
            new OA\Response(response: 404, description: 'Document introuvable'),
        ]
    )]
    public function download(string $id, Request $request)
    {
        $document = $this->documentOrFail($id, $request);
        if (! $this->documents->hasStoredFile($document)) {
            abort(404, 'Le fichier de ce document est introuvable.');
        }

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    #[OA\Post(
        path: '/employees/{employeeId}/documents/generate',
        tags: ['HR Documents'],
        summary: "Rédige une attestation (.docx) et la range dans le dossier de l'employé",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'template', type: 'string', enum: ['WORK_CERTIFICATE', 'SALARY_CERTIFICATE', 'EMPLOYMENT_CERTIFICATE']),
            new OA\Property(property: 'hrRequestId', type: 'string', format: 'uuid', nullable: true, description: 'Demande RH à laquelle le document répond : elle est approuvée'),
            new OA\Property(property: 'signatoryName', type: 'string', nullable: true),
            new OA\Property(property: 'signatoryTitle', type: 'string', nullable: true),
            new OA\Property(property: 'city', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Attestation rédigée', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeDocument')),
            new OA\Response(response: 400, description: 'Document impossible pour cet employé (salaire manquant, employé encore présent…)'),
            new OA\Response(response: 404, description: 'Employé ou demande introuvable'),
        ]
    )]
    public function generate(string $employeeId, Request $request)
    {
        $employee = $this->employeeOrFail($employeeId, $request);
        $data = $request->validate([
            'template' => ['required', Rule::enum(HrDocumentTemplate::class)],
            'hrRequestId' => ['nullable', 'uuid'],
            'signatoryName' => ['nullable', 'string', 'max:120'],
            'signatoryTitle' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
        ]);

        $document = $this->documents->generate($employee, $request->user(), $data);

        return response()->json(EmployeeDocumentResource::fromDocument($document), 201);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function metadataRules(bool $partial): array
    {
        return [
            'employeeId' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'uuid'],
            'category' => [$partial ? 'sometimes' : 'required', Rule::enum(EmployeeDocumentCategory::class)],
            'title' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'issuedAt' => ['sometimes', 'nullable', 'date'],
            'expiresAt' => ['sometimes', 'nullable', 'date', 'after_or_equal:issuedAt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'file.required' => 'Choisissez le fichier à déposer.',
            'file.mimes' => 'Formats acceptés : PDF, image (JPG, PNG, WebP), Word, Excel, OpenDocument ou texte.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
            'category.required' => 'Choisissez une catégorie.',
            'expiresAt.after_or_equal' => "La date d'expiration doit suivre la date de délivrance.",
        ];
    }

    private function employeeOrFail(string $employeeId, Request $request): Employee
    {
        $employee = $this->employees->find($employeeId, $this->enterpriseCompanyId($request));
        if (! $employee) {
            abort(404, 'Employé introuvable.');
        }

        return $employee;
    }

    private function documentOrFail(string $id, Request $request): EmployeeDocument
    {
        $document = $this->documents->find($id, $this->enterpriseCompanyId($request));
        if (! $document) {
            abort(404, 'Document introuvable.');
        }

        return $document;
    }
}
