<?php

namespace App\Services;

use App\Enums\EmployeeDocumentCategory;
use App\Enums\HrDocumentTemplate;
use App\Enums\HrWorkflowStatus;
use App\Enums\PayrollRunStatus;
use App\Http\Resources\EmployeeDocumentResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\HrRequest;
use App\Models\Payslip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documents RH d'une entreprise, rangés en un seul endroit.
 *
 * Deux sortes de documents propres au module — fichiers déposés et attestations
 * rédigées par Goriya — auxquels la liste ajoute, en lecture seule, les
 * contrats signés (Contrats) et les bulletins des paies validées (Paie) : ils
 * se gèrent dans leur module, mais se retrouvent ici.
 */
class EmployeeDocumentService
{
    private const DISK = 'local';

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const RELATIONS = ['employee', 'uploader'];

    public function __construct(
        private readonly HrCertificateService $certificates,
        private readonly HrRequestService $hrRequests,
    ) {}

    /**
     * @param  array{employeeId?: ?string, scope?: ?string, category?: ?list<string>, search?: ?string, expiringWithin?: ?int, includeLinked?: ?bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function listForCompany(string $companyId, array $filters = []): array
    {
        $employeeId = $filters['employeeId'] ?? null;
        $scope = $employeeId ? 'employees' : ($filters['scope'] ?? 'all');
        $categories = $filters['category'] ?? [];
        $search = trim((string) ($filters['search'] ?? ''));
        $expiringWithin = $filters['expiringWithin'] ?? null;

        $query = EmployeeDocument::query()->where('company_id', $companyId)->with(self::RELATIONS);

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        } elseif ($scope === 'company') {
            $query->whereNull('employee_id');
        } elseif ($scope === 'employees') {
            $query->whereNotNull('employee_id');
        }
        if ($categories !== []) {
            $query->whereIn('category', $categories);
        }
        if ($expiringWithin !== null) {
            // Échéances : documents expirés compris, c'est ce qu'il faut traiter d'abord.
            $query->whereNotNull('expires_at')->whereDate('expires_at', '<=', now()->addDays((int) $expiringWithin)->toDateString());
        }
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn (Builder $e) => $e->where(function (Builder $w) use ($search) {
                        foreach (['first_name', 'last_name', 'matricule'] as $column) {
                            $w->orWhere($column, 'like', "%{$search}%");
                        }
                    }));
            });
        }

        $items = $query->get()->map(fn (EmployeeDocument $document) => EmployeeDocumentResource::fromDocument($document))->all();

        // Documents d'autres modules : sans date d'expiration, et jamais au niveau entreprise.
        $withLinked = ($filters['includeLinked'] ?? true) && $scope !== 'company' && $expiringWithin === null;
        if ($withLinked) {
            if ($categories === [] || in_array(EmployeeDocumentCategory::CONTRACT->value, $categories, true)) {
                $items = array_merge($items, $this->signedContracts($companyId, $employeeId, $search));
            }
            if ($categories === [] || in_array(EmployeeDocumentCategory::PAYROLL->value, $categories, true)) {
                $items = array_merge($items, $this->validatedPayslips($companyId, $employeeId, $search));
            }
        }

        $sortKey = fn (array $item) => ($item['issuedAt'] ?? substr((string) $item['createdAt'], 0, 10)).' '.$item['createdAt'];
        usort($items, fn (array $a, array $b) => strcmp($sortKey($b), $sortKey($a)));

        return $items;
    }

    public function find(string $id, string $companyId): ?EmployeeDocument
    {
        return EmployeeDocument::where('company_id', $companyId)->with(self::RELATIONS)->find($id);
    }

    /**
     * @param  array{file: UploadedFile, category: string, title?: ?string, description?: ?string, issuedAt?: ?string, expiresAt?: ?string}  $data
     */
    public function upload(string $companyId, ?Employee $employee, User $author, array $data): EmployeeDocument
    {
        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin'));
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $path = $file->storeAs("hr-documents/{$companyId}", Str::uuid().'.'.$extension, self::DISK);

        $document = EmployeeDocument::create([
            'company_id' => $companyId,
            'employee_id' => $employee?->id,
            'category' => $data['category'],
            // Sans titre saisi, le nom du fichier en tient lieu.
            'title' => trim((string) ($data['title'] ?? '')) ?: Str::limit($original, 150, ''),
            'description' => $data['description'] ?? null,
            'file_path' => $path,
            'file_name' => (Str::slug($original) ?: 'document').".{$extension}",
            'mime_type' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'issued_at' => $data['issuedAt'] ?? null,
            'expires_at' => $data['expiresAt'] ?? null,
            'source' => EmployeeDocument::SOURCE_UPLOAD,
            'uploaded_by' => $author->id,
        ]);

        return $this->reload($document);
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (camelCase) ; `employeeId` déjà vérifié par le contrôleur
     */
    public function update(EmployeeDocument $document, array $data): EmployeeDocument
    {
        $attributes = [];
        foreach (['title' => 'title', 'category' => 'category', 'description' => 'description', 'issuedAt' => 'issued_at', 'expiresAt' => 'expires_at', 'employeeId' => 'employee_id'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $attributes[$column] = $data[$field];
            }
        }

        if (array_key_exists('title', $attributes) && trim((string) $attributes['title']) === '') {
            abort(400, 'Le document doit garder un titre.');
        }
        if (array_key_exists('employee_id', $attributes)
            && $attributes['employee_id'] !== $document->employee_id
            && $document->source === EmployeeDocument::SOURCE_GENERATED) {
            abort(400, "Une attestation générée concerne l'employé pour qui elle a été rédigée : elle ne change pas de dossier.");
        }

        $issued = array_key_exists('issued_at', $attributes) ? $attributes['issued_at'] : $document->issued_at?->toDateString();
        $expires = array_key_exists('expires_at', $attributes) ? $attributes['expires_at'] : $document->expires_at?->toDateString();
        if ($issued && $expires && CarbonImmutable::parse($expires)->lt(CarbonImmutable::parse($issued))) {
            abort(400, "La date d'expiration doit suivre la date de délivrance.");
        }

        $document->update($attributes);

        return $this->reload($document);
    }

    public function delete(EmployeeDocument $document): void
    {
        Storage::disk(self::DISK)->delete($document->file_path);
        $document->delete();
    }

    public function hasStoredFile(EmployeeDocument $document): bool
    {
        return Storage::disk(self::DISK)->exists($document->file_path);
    }

    /** Fichiers d'un employé, à effacer avant de supprimer sa fiche (les lignes partent en cascade). */
    public function deleteFilesOf(Employee $employee): void
    {
        $employee->documents()->pluck('file_path')->each(fn (string $path) => Storage::disk(self::DISK)->delete($path));
    }

    /**
     * Rédige une attestation et la range dans le dossier de l'employé. Rédigée
     * en réponse à une demande RH, elle approuve cette demande.
     *
     * @param  array{template: string, hrRequestId?: ?string, signatoryName?: ?string, signatoryTitle?: ?string, city?: ?string}  $data
     */
    public function generate(Employee $employee, User $author, array $data): EmployeeDocument
    {
        $template = HrDocumentTemplate::from($data['template']);
        $hrRequest = empty($data['hrRequestId']) ? null : $this->answerableRequest($employee, $template, $data['hrRequestId']);
        $issuedAt = CarbonImmutable::now();

        $path = $this->certificates->generate($employee, $template, [
            'signatoryName' => trim((string) ($data['signatoryName'] ?? '')) ?: $author->name,
            'signatoryTitle' => trim((string) ($data['signatoryTitle'] ?? '')) ?: 'Responsable des ressources humaines',
            'city' => trim((string) ($data['city'] ?? '')) ?: null,
            'date' => $issuedAt,
        ]);

        $document = DB::transaction(function () use ($employee, $author, $template, $hrRequest, $issuedAt, $path) {
            $document = EmployeeDocument::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'category' => EmployeeDocumentCategory::CERTIFICATE,
                'title' => $template->title().' du '.$issuedAt->locale('fr')->isoFormat('D MMMM YYYY'),
                'file_path' => $path,
                'file_name' => $template->slug().'-'.Str::slug($employee->matricule).'-'.$issuedAt->toDateString().'.docx',
                'mime_type' => self::DOCX,
                'size' => (int) Storage::disk(self::DISK)->size($path),
                'issued_at' => $issuedAt->toDateString(),
                'source' => EmployeeDocument::SOURCE_GENERATED,
                'template' => $template,
                'hr_request_id' => $hrRequest?->id,
                'uploaded_by' => $author->id,
            ]);

            if ($hrRequest && $hrRequest->status !== HrWorkflowStatus::APPROVED) {
                $this->hrRequests->decide($hrRequest, $author, HrWorkflowStatus::APPROVED, "Document délivré : {$document->title}.");
            }

            return $document;
        });

        return $this->reload($document);
    }

    private function answerableRequest(Employee $employee, HrDocumentTemplate $template, string $hrRequestId): HrRequest
    {
        $hrRequest = HrRequest::where('employee_id', $employee->id)->find($hrRequestId);

        if (! $hrRequest) {
            abort(404, 'Demande introuvable pour cet employé.');
        }
        if (! $template->answers($hrRequest->type)) {
            abort(400, 'Cette demande ne porte pas sur ce document.');
        }
        if (in_array($hrRequest->status, [HrWorkflowStatus::REJECTED, HrWorkflowStatus::CANCELLED], true)) {
            abort(400, 'Cette demande a été refusée ou annulée.');
        }

        return $hrRequest;
    }

    /**
     * Contrats dont l'exemplaire signé a été joint (module Contrats).
     *
     * @return list<array<string, mixed>>
     */
    private function signedContracts(string $companyId, ?string $employeeId, string $search): array
    {
        return EmployeeContract::query()
            ->where('company_id', $companyId)
            ->whereNotNull('document_path')
            ->when($employeeId, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->with('employee')
            ->get()
            ->map(fn (EmployeeContract $contract) => $this->linked([
                'id' => $contract->id,
                'source' => 'CONTRACT',
                'category' => EmployeeDocumentCategory::CONTRACT->value,
                'title' => "Contrat signé {$contract->reference}",
                'description' => $contract->job_title,
                'fileName' => $contract->document_name,
                'mimeType' => $this->mimeFromName((string) $contract->document_name),
                'issuedAt' => ($contract->signed_at ?? $contract->document_uploaded_at)?->toDateString(),
                'employee' => $contract->employee ? EmployeeResource::summary($contract->employee) : null,
                'downloadPath' => "/employee-contracts/{$contract->id}/document",
                'createdAt' => $contract->document_uploaded_at?->toIso8601String(),
            ]))
            ->filter(fn (array $item) => $this->matches($item, $search))
            ->values()
            ->all();
    }

    /**
     * Bulletins des paies validées ou payées (module Paie). Sans employé précis,
     * les douze derniers mois seulement : l'historique complet reste sur la fiche.
     *
     * @return list<array<string, mixed>>
     */
    private function validatedPayslips(string $companyId, ?string $employeeId, string $search): array
    {
        $query = Payslip::query()
            ->where('company_id', $companyId)
            ->whereNotNull('employee_id')
            ->whereHas('run', fn (Builder $q) => $q->whereIn('status', [PayrollRunStatus::VALIDATED->value, PayrollRunStatus::PAID->value]))
            ->with(['run', 'employee']);

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        } else {
            $since = now()->subMonths(12);
            $query->whereHas('run', fn (Builder $q) => $q->whereRaw('(year * 100 + month) >= ?', [$since->year * 100 + $since->month]));
        }

        return $query->get()
            ->filter(fn (Payslip $payslip) => $payslip->employee !== null)
            ->map(function (Payslip $payslip) {
                $run = $payslip->run;
                $period = CarbonImmutable::create($run->year, $run->month, 1);

                return $this->linked([
                    'id' => $payslip->id,
                    'source' => 'PAYSLIP',
                    'category' => EmployeeDocumentCategory::PAYROLL->value,
                    'title' => 'Bulletin de paie — '.$period->locale('fr')->isoFormat('MMMM YYYY'),
                    'fileName' => sprintf('bulletin-%04d-%02d-%s.docx', $run->year, $run->month, $payslip->employee->matricule),
                    'mimeType' => self::DOCX,
                    'issuedAt' => $run->validated_at?->toDateString() ?? $period->endOfMonth()->toDateString(),
                    'employee' => EmployeeResource::summary($payslip->employee),
                    'downloadPath' => "/payslips/{$payslip->id}/document",
                    'createdAt' => $run->validated_at?->toIso8601String(),
                ]);
            })
            ->filter(fn (array $item) => $this->matches($item, $search))
            ->values()
            ->all();
    }

    /**
     * Document d'un autre module : mêmes clés qu'EmployeeDocumentResource, non modifiable ici.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function linked(array $values): array
    {
        return array_merge([
            'description' => null,
            'size' => null,
            'expiresAt' => null,
            'uploadedByName' => null,
            'template' => null,
            'hrRequestId' => null,
            'editable' => false,
        ], $values);
    }

    /** @param  array<string, mixed>  $item */
    private function matches(array $item, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        $haystack = implode(' ', [$item['title'], $item['fileName'], $item['employee']['fullName'] ?? '', $item['employee']['matricule'] ?? '']);

        return mb_stripos($haystack, $search) !== false;
    }

    private function mimeFromName(string $name): ?string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => null,
        };
    }

    private function reload(EmployeeDocument $document): EmployeeDocument
    {
        return EmployeeDocument::with(self::RELATIONS)->findOrFail($document->id);
    }
}
