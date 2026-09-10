<?php

namespace App\Services;

use App\Enums\CandidatureStatus;
use App\Enums\HrWorkflowStatus;
use App\Enums\LeaveType;
use App\Models\Candidature;
use App\Models\Employee;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Répertoire des employés d'une entreprise. Toutes les lectures sont bornées à
 * `company_id` : un identifiant d'une autre entreprise se comporte comme un
 * identifiant inexistant.
 */
class EmployeeService
{
    use MapsFieldsToColumns;

    /** Correspondance champs d'API (camelCase) → colonnes. */
    private const FIELDS = [
        'managerId' => 'manager_id',
        'matricule' => 'matricule',
        'firstName' => 'first_name',
        'lastName' => 'last_name',
        'gender' => 'gender',
        'birthDate' => 'birth_date',
        'email' => 'email',
        'phone' => 'phone',
        'address' => 'address',
        'jobTitle' => 'job_title',
        'department' => 'department',
        'contractType' => 'contract_type',
        'hireDate' => 'hire_date',
        'contractEndDate' => 'contract_end_date',
        'salary' => 'salary',
        'annualLeaveDays' => 'annual_leave_days',
        'status' => 'status',
        'emergencyContactName' => 'emergency_contact_name',
        'emergencyContactPhone' => 'emergency_contact_phone',
        'notes' => 'notes',
    ];

    /**
     * @param  array{search?: ?string, status?: ?string, department?: ?string}  $filters
     */
    public function list(string $companyId, array $filters = []): Collection
    {
        $query = $this->withListingData(Employee::query()->where('company_id', $companyId));

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                foreach (['first_name', 'last_name', 'matricule', 'email', 'job_title', 'department'] as $column) {
                    $q->orWhere($column, 'like', "%{$search}%");
                }
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($department = $filters['department'] ?? null) {
            $query->where('department', $department);
        }

        return $query->orderBy('last_name')->orderBy('first_name')->get();
    }

    public function find(string $id, string $companyId): ?Employee
    {
        return $this->withListingData(Employee::query()->where('company_id', $companyId))->find($id);
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (camelCase)
     */
    public function create(string $companyId, array $data): Employee
    {
        $attributes = $this->mapFields($data, self::FIELDS);
        $this->assertManager($companyId, $attributes['manager_id'] ?? null, null);

        if (! empty($data['candidatureId'])) {
            $candidature = $this->hireableCandidature($companyId, $data['candidatureId']);
            $attributes['candidature_id'] = $candidature->id;
            $attributes['user_id'] = $candidature->user_id;
        }

        if (empty($attributes['matricule'])) {
            $attributes['matricule'] = $this->nextMatricule($companyId);
        }
        // Colonnes à défaut DB : un `null` explicite violerait NOT NULL.
        foreach (['status', 'annual_leave_days'] as $column) {
            if (array_key_exists($column, $attributes) && $attributes[$column] === null) {
                unset($attributes[$column]);
            }
        }

        $employee = Employee::create($attributes + ['company_id' => $companyId]);

        return $this->find($employee->id, $companyId);
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (camelCase)
     */
    public function update(Employee $employee, array $data): Employee
    {
        $attributes = $this->mapFields($data, self::FIELDS);

        if (array_key_exists('manager_id', $attributes)) {
            $this->assertManager($employee->company_id, $attributes['manager_id'], $employee->id);
        }
        // Un matricule vidé garde l'ancien : c'est l'identifiant de la fiche.
        if (array_key_exists('matricule', $attributes) && empty($attributes['matricule'])) {
            unset($attributes['matricule']);
        }
        foreach (['status', 'annual_leave_days'] as $column) {
            if (array_key_exists($column, $attributes) && $attributes[$column] === null) {
                unset($attributes[$column]);
            }
        }

        $employee->update($attributes);

        return $this->find($employee->id, $employee->company_id);
    }

    public function delete(Employee $employee): void
    {
        $employee->delete();
    }

    /**
     * Solde de congés payés de l'année civile : droit annuel, jours déjà pris
     * (congés approuvés), jours en attente de décision, et reste disponible.
     * Seuls les congés de type PAID sont décomptés.
     *
     * @return array{year: int, entitlement: int, taken: int, pending: int, remaining: int}
     */
    public function leaveBalance(Employee $employee, ?int $year = null): array
    {
        $year ??= (int) now()->year;

        $sums = $employee->leaves()
            ->where('type', LeaveType::PAID->value)
            ->whereYear('start_date', $year)
            ->whereIn('status', [HrWorkflowStatus::APPROVED->value, HrWorkflowStatus::PENDING->value])
            ->selectRaw('status, SUM(days) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $taken = (int) ($sums[HrWorkflowStatus::APPROVED->value] ?? 0);
        $entitlement = (int) $employee->annual_leave_days;

        return [
            'year' => $year,
            'entitlement' => $entitlement,
            'taken' => $taken,
            'pending' => (int) ($sums[HrWorkflowStatus::PENDING->value] ?? 0),
            'remaining' => $entitlement - $taken,
        ];
    }

    /**
     * Candidatures acceptées sur les offres de l'entreprise et pas encore
     * embauchées, avec la fiche employé pré-remplie depuis le système Goriya :
     * compte du candidat (nom, e-mail, téléphone, localisation) et offre
     * (intitulé du poste, type de contrat). Le formulaire d'ajout s'ouvre sur
     * ces valeurs, les RH complètent le reste (date d'embauche, salaire…).
     *
     * @return list<array<string, mixed>>
     */
    public function hireableCandidatures(string $companyId): array
    {
        return $this->hireableQuery($companyId)
            ->with(['user', 'jobOffer'])
            ->orderByDesc('applied_date')
            ->get()
            ->map(fn (Candidature $candidature) => [
                'id' => $candidature->id,
                'candidateName' => $candidature->user?->name ?? $candidature->candidate_name,
                'candidateEmail' => $candidature->user?->email ?? $candidature->candidate_email,
                'jobOfferId' => $candidature->job_offer_id,
                'jobOfferTitle' => $candidature->jobOffer?->title,
                'appliedDate' => $candidature->applied_date?->toDateString(),
                'score' => $candidature->score,
                'prefill' => $this->prefillFromCandidature($candidature),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed> Champs du formulaire employé (camelCase)
     */
    private function prefillFromCandidature(Candidature $candidature): array
    {
        $user = $candidature->user;
        // « Marie Dubois » → prénom « Marie », nom « Dubois ». Un nom composé
        // reste modifiable dans le formulaire : on ne cherche pas à deviner.
        $parts = preg_split('/\s+/', trim((string) ($user?->name ?? $candidature->candidate_name)), 2) ?: [];

        return [
            'firstName' => $parts[0] ?? '',
            'lastName' => $parts[1] ?? '',
            'email' => $user?->email ?? $candidature->candidate_email,
            'phone' => $candidature->candidate_phone ?: $user?->phone,
            'address' => $candidature->candidate_location ?: $user?->location,
            'jobTitle' => $candidature->jobOffer?->title,
            'contractType' => $candidature->jobOffer?->type?->value,
        ];
    }

    private function hireableQuery(string $companyId): Builder
    {
        return Candidature::query()
            ->where('status', CandidatureStatus::APPROUVEE->value)
            ->whereHas('jobOffer', fn (Builder $q) => $q->where('company_id', $companyId))
            ->whereNotIn('id', Employee::query()
                ->where('company_id', $companyId)
                ->whereNotNull('candidature_id')
                ->select('candidature_id'));
    }

    /**
     * Vérifie qu'une candidature peut donner lieu à une embauche : elle vise une
     * offre de l'entreprise, elle est acceptée, et personne n'a encore été
     * embauché à partir d'elle.
     */
    private function hireableCandidature(string $companyId, string $candidatureId): Candidature
    {
        $candidature = Candidature::query()
            ->whereHas('jobOffer', fn (Builder $q) => $q->where('company_id', $companyId))
            ->find($candidatureId);

        if (! $candidature) {
            abort(404, 'Candidature introuvable.');
        }
        if ($candidature->status !== CandidatureStatus::APPROUVEE) {
            abort(400, "Seule une candidature acceptée peut donner lieu à une embauche.");
        }
        if (Employee::where('candidature_id', $candidature->id)->exists()) {
            abort(400, 'Ce candidat a déjà été embauché à partir de cette candidature.');
        }

        return $candidature;
    }

    /** Relations et compteurs affichés dans la liste comme dans la fiche. */
    private function withListingData(Builder $query): Builder
    {
        return $query
            ->with(['manager', 'currentLeaves', 'candidature.jobOffer'])
            ->withCount([
                'leaves as pending_leaves_count' => fn (Builder $q) => $q->where('status', HrWorkflowStatus::PENDING->value),
                'hrRequests as pending_requests_count' => fn (Builder $q) => $q->whereIn('status', [
                    HrWorkflowStatus::PENDING->value,
                    HrWorkflowStatus::IN_PROGRESS->value,
                ]),
            ]);
    }

    /**
     * Le responsable doit exister dans la même entreprise, et ne peut pas être
     * l'employé lui-même.
     */
    private function assertManager(string $companyId, ?string $managerId, ?string $employeeId): void
    {
        if (! $managerId) {
            return;
        }
        if ($managerId === $employeeId) {
            abort(400, 'Un employé ne peut pas être son propre responsable.');
        }
        if (! Employee::where('company_id', $companyId)->whereKey($managerId)->exists()) {
            abort(400, 'Responsable introuvable dans votre entreprise.');
        }
    }

    /**
     * Prochain matricule libre au format EMP-0001. Part du nombre d'employés et
     * avance tant que le numéro est pris (fiches supprimées, matricules saisis
     * à la main dans le même format).
     */
    private function nextMatricule(string $companyId): string
    {
        $number = Employee::where('company_id', $companyId)->count() + 1;

        do {
            $matricule = sprintf('EMP-%04d', $number++);
        } while (Employee::where('company_id', $companyId)->where('matricule', $matricule)->exists());

        return $matricule;
    }
}
