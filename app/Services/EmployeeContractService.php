<?php

namespace App\Services;

use App\Enums\ContractKind;
use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Enums\JobType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Services\Concerns\MapsFieldsToColumns;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Contrats de travail des employés.
 *
 * Deux invariants :
 *  - un employé a au plus un contrat en vigueur (ACTIVE) : en mettre un en
 *    vigueur clôt le précédent la veille de son début ;
 *  - la fiche employé (type, date de fin, salaire, poste) reflète le contrat en
 *    vigueur, qu'elle ne peut plus contredire (voir EmployeeService::update).
 *
 * Un contrat mis en vigueur fait partie du dossier : il ne se réécrit plus
 * (on établit un avenant) et ne se supprime pas (seul un brouillon le peut).
 */
class EmployeeContractService
{
    use MapsFieldsToColumns;

    /** Types de contrat qui ont nécessairement une date de fin. */
    public const FIXED_TERM_TYPES = [JobType::CDD, JobType::STAGE, JobType::ALTERNANCE];

    private const DISK = 'local';

    private const TRANSITIONS = [
        'DRAFT' => ['ACTIVE'],
        'ACTIVE' => ['ENDED', 'TERMINATED'],
    ];

    private const FIELDS = [
        'type' => 'type',
        'jobTitle' => 'job_title',
        'startDate' => 'start_date',
        'endDate' => 'end_date',
        'trialEndDate' => 'trial_end_date',
        'salary' => 'salary',
        'weeklyHours' => 'weekly_hours',
        'signedAt' => 'signed_at',
        'notes' => 'notes',
    ];

    /** Seules colonnes encore modifiables une fois le contrat sorti du brouillon. */
    private const EDITABLE_AFTER_DRAFT = ['signed_at', 'notes'];

    private const RELATIONS = ['employee', 'parent', 'creator'];

    /**
     * @param  array{status?: ?list<string>, type?: ?list<string>, employeeId?: ?string, search?: ?string}  $filters
     */
    public function listForCompany(string $companyId, array $filters = []): Collection
    {
        $query = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->with(['employee', 'parent']);

        if (! empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->whereIn('type', $filters['type']);
        }
        if ($employeeId = $filters['employeeId'] ?? null) {
            $query->where('employee_id', $employeeId);
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn (Builder $e) => $e->where(function (Builder $w) use ($search) {
                        foreach (['first_name', 'last_name', 'matricule'] as $column) {
                            $w->orWhere($column, 'like', "%{$search}%");
                        }
                    }));
            });
        }

        return $query->orderByDesc('start_date')->orderByDesc('created_at')->get();
    }

    public function listFor(Employee $employee): Collection
    {
        return $employee->contracts()
            ->with(['parent', 'creator'])
            ->orderByDesc('start_date')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(string $id, string $companyId): ?EmployeeContract
    {
        return EmployeeContract::where('company_id', $companyId)->with(self::RELATIONS)->find($id);
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (camelCase)
     */
    public function create(Employee $employee, ?User $author, array $data): EmployeeContract
    {
        $kind = ContractKind::from($data['kind'] ?? ContractKind::INITIAL->value);
        $parent = null;

        if ($kind !== ContractKind::INITIAL) {
            $parent = empty($data['parentId'])
                ? null
                : EmployeeContract::where('employee_id', $employee->id)->find($data['parentId']);
            if (! $parent) {
                abort(400, $kind === ContractKind::RENEWAL
                    ? 'Indiquez le contrat renouvelé.'
                    : "Indiquez le contrat modifié par l'avenant.");
            }
            if ($parent->status === ContractStatus::DRAFT) {
                abort(400, "Un brouillon ne se renouvelle pas et ne reçoit pas d'avenant : modifiez-le directement.");
            }
        }

        $this->assertTerms($data['type'], $data['startDate'], $data['endDate'] ?? null, $data['trialEndDate'] ?? null);
        $activate = ($data['status'] ?? ContractStatus::DRAFT->value) === ContractStatus::ACTIVE->value;

        return DB::transaction(function () use ($employee, $author, $data, $kind, $parent, $activate) {
            $contract = EmployeeContract::create($this->mapFields($data, self::FIELDS) + [
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'parent_id' => $parent?->id,
                'reference' => $this->nextReference($employee->company_id),
                'kind' => $kind,
                'status' => ContractStatus::DRAFT,
                'created_by' => $author?->id,
            ]);

            if ($activate) {
                $this->activate($contract);
            }

            return $this->reload($contract);
        });
    }

    /**
     * Contrat initial ouvert à la création d'un employé, d'après sa fiche : le
     * formulaire d'ajout porte déjà type, dates et salaire. Un employé saisi
     * comme déjà parti reçoit un contrat terminé, jamais « en vigueur ».
     */
    public function createInitialFor(Employee $employee, ?User $author = null): EmployeeContract
    {
        return EmployeeContract::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'reference' => $this->nextReference($employee->company_id),
            'kind' => ContractKind::INITIAL,
            'type' => $employee->contract_type,
            'job_title' => $employee->job_title,
            'start_date' => $employee->hire_date,
            'end_date' => $employee->contract_end_date,
            'salary' => $employee->salary,
            'status' => $employee->status === EmployeeStatus::TERMINATED ? ContractStatus::ENDED : ContractStatus::ACTIVE,
            'created_by' => $author?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (camelCase)
     */
    public function update(EmployeeContract $contract, array $data): EmployeeContract
    {
        $attributes = $this->mapFields($data, self::FIELDS);

        if ($contract->status !== ContractStatus::DRAFT) {
            if (array_diff(array_keys($attributes), self::EDITABLE_AFTER_DRAFT) !== []) {
                abort(400, "Un contrat mis en vigueur ne se réécrit pas : établissez un avenant. Seules la date de signature et les notes restent modifiables.");
            }
        } else {
            $this->assertTerms(
                $attributes['type'] ?? $contract->type,
                $this->dateOf($attributes['start_date'] ?? $contract->start_date),
                $this->dateOf(array_key_exists('end_date', $attributes) ? $attributes['end_date'] : $contract->end_date),
                $this->dateOf(array_key_exists('trial_end_date', $attributes) ? $attributes['trial_end_date'] : $contract->trial_end_date),
            );
        }

        $contract->update($attributes);

        return $this->reload($contract);
    }

    /**
     * @param  array{endDate?: ?string, terminationDate?: ?string, terminationReason?: ?string, markEmployeeDeparted?: ?bool}  $data
     */
    public function changeStatus(EmployeeContract $contract, ContractStatus $status, array $data = []): EmployeeContract
    {
        $allowed = self::TRANSITIONS[$contract->status->value] ?? [];
        if (! in_array($status->value, $allowed, true)) {
            abort(400, 'Ce contrat ne peut pas passer à ce statut.');
        }

        return DB::transaction(function () use ($contract, $status, $data) {
            match ($status) {
                ContractStatus::ACTIVE => $this->activate($contract),
                ContractStatus::ENDED => $this->end($contract, $data['endDate'] ?? null),
                ContractStatus::TERMINATED => $this->terminate($contract, $data),
                default => null,
            };

            return $this->reload($contract);
        });
    }

    public function delete(EmployeeContract $contract): void
    {
        if ($contract->status !== ContractStatus::DRAFT) {
            abort(400, 'Seul un brouillon se supprime : un contrat mis en vigueur fait partie du dossier.');
        }

        $this->deleteStoredDocument($contract);
        $contract->delete();
    }

    /**
     * Joint le contrat signé. Un document signé vaut signature : la date de
     * signature est renseignée si elle manquait (modifiable ensuite).
     */
    public function attachDocument(EmployeeContract $contract, UploadedFile $file): EmployeeContract
    {
        $this->deleteStoredDocument($contract);

        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'pdf'));
        $path = $file->storeAs("employee-contracts/{$contract->company_id}", Str::uuid().'.'.$extension, self::DISK);
        $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'contrat-'.Str::lower($contract->reference);

        $contract->update([
            'document_path' => $path,
            'document_name' => "{$baseName}.{$extension}",
            'document_uploaded_at' => now(),
            'signed_at' => $contract->signed_at ?? now()->toDateString(),
        ]);

        return $this->reload($contract);
    }

    public function removeDocument(EmployeeContract $contract): EmployeeContract
    {
        $this->deleteStoredDocument($contract);
        $contract->update(['document_path' => null, 'document_name' => null, 'document_uploaded_at' => null]);

        return $this->reload($contract);
    }

    public function hasStoredDocument(EmployeeContract $contract): bool
    {
        return $contract->document_path !== null && Storage::disk(self::DISK)->exists($contract->document_path);
    }

    /** Fichiers des contrats d'un employé, à effacer avant de supprimer sa fiche. */
    public function deleteDocumentsOf(Employee $employee): void
    {
        $employee->contracts()
            ->whereNotNull('document_path')
            ->pluck('document_path')
            ->each(fn (string $path) => Storage::disk(self::DISK)->delete($path));
    }

    /**
     * Mise en vigueur : clôt le contrat précédent la veille du début de celui-ci
     * (ou à sa fin prévue si elle tombe avant) et aligne la fiche employé.
     */
    private function activate(EmployeeContract $contract): void
    {
        $employee = $contract->employee()->firstOrFail();
        if ($employee->status === EmployeeStatus::TERMINATED) {
            abort(400, "L'employé a quitté l'entreprise : changez son statut avant de mettre un contrat en vigueur.");
        }

        $start = $this->dateOf($contract->start_date);
        $this->assertTerms($contract->type, $start, $this->dateOf($contract->end_date), $this->dateOf($contract->trial_end_date));

        $dayBefore = CarbonImmutable::parse($start)->subDay()->toDateString();
        $previous = EmployeeContract::where('employee_id', $employee->id)
            ->where('status', ContractStatus::ACTIVE->value)
            ->whereKeyNot($contract->id)
            ->get();

        foreach ($previous as $replaced) {
            $plannedEnd = $this->dateOf($replaced->end_date);
            $replaced->update([
                'status' => ContractStatus::ENDED,
                'end_date' => $plannedEnd !== null && $plannedEnd < $start
                    ? $plannedEnd
                    : max($dayBefore, $this->dateOf($replaced->start_date)),
            ]);
        }

        $contract->update(['status' => ContractStatus::ACTIVE]);

        $changes = [
            'contract_type' => $contract->type,
            'contract_end_date' => $this->dateOf($contract->end_date),
        ];
        if ($contract->salary !== null) {
            $changes['salary'] = $contract->salary;
        }
        if ($contract->job_title) {
            $changes['job_title'] = $contract->job_title;
        }
        $employee->update($changes);
    }

    /** Arrivée à terme : la date de fin retenue devient celle de la fiche employé. */
    private function end(EmployeeContract $contract, ?string $endDate): void
    {
        $end = $this->dateOf($endDate) ?? $this->dateOf($contract->end_date) ?? now()->toDateString();
        if ($end < $this->dateOf($contract->start_date)) {
            abort(400, 'La date de fin doit suivre la date de début du contrat.');
        }

        $contract->update(['status' => ContractStatus::ENDED, 'end_date' => $end]);
        $contract->employee()->first()?->update(['contract_end_date' => $end]);
    }

    /**
     * Rupture avant terme. La date de fin prévue est conservée (elle dit ce qui
     * était convenu) ; la date de rupture est à part.
     *
     * @param  array{terminationDate?: ?string, terminationReason?: ?string, markEmployeeDeparted?: ?bool}  $data
     */
    private function terminate(EmployeeContract $contract, array $data): void
    {
        $date = $this->dateOf($data['terminationDate'] ?? null) ?? now()->toDateString();
        if ($date < $this->dateOf($contract->start_date)) {
            abort(400, 'La date de rupture doit suivre le début du contrat.');
        }

        $contract->update([
            'status' => ContractStatus::TERMINATED,
            'termination_date' => $date,
            'termination_reason' => $data['terminationReason'] ?? null,
        ]);

        $changes = ['contract_end_date' => $date];
        if (! empty($data['markEmployeeDeparted'])) {
            $changes['status'] = EmployeeStatus::TERMINATED;
        }
        $contract->employee()->first()?->update($changes);
    }

    /**
     * Cohérence des conditions : date de fin exigée pour un contrat à terme,
     * fin après début, période d'essai comprise dans le contrat.
     */
    private function assertTerms(JobType|string|null $type, ?string $start, ?string $end, ?string $trialEnd): void
    {
        $type = $type instanceof JobType ? $type : JobType::tryFrom((string) $type);

        if ($type && in_array($type, self::FIXED_TERM_TYPES, true) && ! $end) {
            abort(400, 'La date de fin est obligatoire pour ce type de contrat.');
        }
        if ($start && $end && $end < $start) {
            abort(400, 'La date de fin doit suivre la date de début.');
        }
        if ($trialEnd && $start && $trialEnd < $start) {
            abort(400, "La fin de la période d'essai doit suivre le début du contrat.");
        }
        if ($trialEnd && $end && $trialEnd > $end) {
            abort(400, "La période d'essai ne peut pas dépasser la fin du contrat.");
        }
    }

    /** Date « aaaa-mm-jj » comparable comme une chaîne, ou null. */
    private function dateOf(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value)->toDateString();
        }

        return null;
    }

    private function deleteStoredDocument(EmployeeContract $contract): void
    {
        if ($contract->document_path) {
            Storage::disk(self::DISK)->delete($contract->document_path);
        }
    }

    /** Référence lisible et unique dans l'entreprise : CTR-0001, CTR-0002… */
    private function nextReference(string $companyId): string
    {
        $number = EmployeeContract::where('company_id', $companyId)->count() + 1;

        do {
            $reference = sprintf('CTR-%04d', $number++);
        } while (EmployeeContract::where('company_id', $companyId)->where('reference', $reference)->exists());

        return $reference;
    }

    private function reload(EmployeeContract $contract): EmployeeContract
    {
        return EmployeeContract::with(self::RELATIONS)->findOrFail($contract->id);
    }
}
