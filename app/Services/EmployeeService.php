<?php

namespace App\Services;

use App\Contracts\AiAnalysisServiceInterface;
use App\Enums\CandidatureStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HrWorkflowStatus;
use App\Enums\LeaveType;
use App\Http\Resources\EmployeeResource;
use App\Mail\EmployeeHiredMail;
use App\Models\Candidature;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

    public function __construct(
        private readonly EmployeeContractService $contracts,
        private readonly RecruitmentService $recruitment,
        private readonly EmployeeDocumentService $documents,
        private readonly NotificationService $notifications,
        private readonly AiAnalysisServiceInterface $aiAnalysisService,
    ) {}

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
    public function create(string $companyId, array $data, ?User $author = null): Employee
    {
        $attributes = $this->mapFields($data, self::FIELDS);
        $this->assertManager($companyId, $attributes['manager_id'] ?? null, null);

        $candidature = null;
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

        // Le formulaire d'ajout porte type, dates et salaire : ils ouvrent le
        // contrat initial, pour que l'historique des contrats commence avec la fiche.
        $employee = DB::transaction(function () use ($attributes, $companyId, $author, $candidature) {
            $employee = Employee::create($attributes + ['company_id' => $companyId]);
            $this->contracts->createInitialFor($employee, $author);
            // Le candidat quitte le pipeline de recrutement : étape « Embauché ».
            if ($candidature) {
                $this->recruitment->markHired($candidature, $author);
            }

            return $employee;
        });

        $employee = $this->find($employee->id, $companyId);
        $this->notifyOnboarding($employee, (bool) $candidature);

        return $employee;
    }

    /**
     * Notification d'embauche — best-effort : ni la notification in-app ni
     * l'email ne doivent faire échouer la création de la fiche.
     *
     * - Depuis une candidature Goriya : notification in-app (l'employé a un
     *   compte) + email.
     * - Saisie manuelle : email seul, quand une adresse est renseignée —
     *   l'employé n'a pas forcément de compte Goriya pour recevoir un in-app.
     */
    private function notifyOnboarding(Employee $employee, bool $fromCandidature): void
    {
        try {
            if ($fromCandidature) {
                $this->notifications->notifyHired($employee);
            }

            if ($employee->email) {
                Mail::to($employee->email)->send(new EmployeeHiredMail($employee, $employee->company));
            }
        } catch (\Throwable $e) {
            Log::error('Employee onboarding notification failed: '.$e->getMessage());
        }
    }

    /**
     * Fiche employé la plus récente de cet utilisateur, tous employeurs
     * confondus — sert l'espace employé (standard/app/(protected)/espace-employe) :
     * le bouton du tableau de bord et les demandes de congés/RH s'appuient
     * dessus plutôt que sur `User.company_id`, qui n'est jamais renseigné pour
     * un compte USER (voir EmployeeSurveysController::requireCompanyId).
     */
    public function findByUser(string $userId): ?Employee
    {
        return $this->withListingData(Employee::query()->where('user_id', $userId))
            ->orderByDesc('hire_date')
            ->first();
    }

    /**
     * Extrait identité/coordonnées/poste d'un CV pour pré-remplir le
     * formulaire d'ajout d'employé — l'utilisateur RH vérifie et complète
     * ensuite (contrat, salaire…), rien n'est enregistré ici.
     *
     * @return array{firstName: ?string, lastName: ?string, email: ?string, phone: ?string, address: ?string, jobTitle: ?string}
     */
    public function extractFromCv(UploadedFile $file): array
    {
        return $this->aiAnalysisService->extractEmployeeInfoFromCv(
            $file->get(),
            (string) $file->getMimeType(),
            $file->getClientOriginalName(),
        );
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

        // Type, date de fin et salaire suivent le contrat en vigueur : les changer
        // ici désynchroniserait la fiche du contrat. Les renvoyer à l'identique
        // (le formulaire complet les contient) reste permis.
        $contractColumns = array_intersect_key($attributes, array_flip(['contract_type', 'contract_end_date', 'salary']));
        if ($contractColumns !== [] && $employee->activeContract()->exists()) {
            foreach ($contractColumns as $column => $value) {
                if ($this->comparable($employee->{$column}) !== $this->comparable($value)) {
                    abort(400, "Le type de contrat, la date de fin et le salaire suivent le contrat en vigueur : modifiez-les par un avenant depuis l'onglet Contrats.");
                }
            }
            $attributes = array_diff_key($attributes, $contractColumns);
        }

        $employee->update($attributes);

        return $this->find($employee->id, $employee->company_id);
    }

    public function delete(Employee $employee): void
    {
        // Les lignes partent en cascade, pas les fichiers : contrats signés et documents RH.
        $this->contracts->deleteDocumentsOf($employee);
        $this->documents->deleteFilesOf($employee);
        $employee->delete();
    }

    /** Valeur comparable entre une colonne castée et une valeur de requête. */
    private function comparable(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }

        return (string) $value;
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

        return $this->balance(
            $year,
            (int) $employee->annual_leave_days,
            (int) ($sums[HrWorkflowStatus::APPROVED->value] ?? 0),
            (int) ($sums[HrWorkflowStatus::PENDING->value] ?? 0),
        );
    }

    /**
     * Soldes de congés payés de tous les employés présents (hors départs), pour
     * la page Congés. Une seule agrégation pour l'entreprise plutôt qu'un calcul
     * par employé : la page reste rapide quel que soit l'effectif.
     *
     * @return list<array<string, mixed>> Identité de l'employé + solde de l'année
     */
    public function leaveBalances(string $companyId, ?int $year = null): array
    {
        $year ??= (int) now()->year;

        // `toBase()` : lignes brutes, sans le cast enum de `status` qui fausserait
        // la comparaison avec les chaînes ci-dessous.
        $sums = EmployeeLeave::query()
            ->where('company_id', $companyId)
            ->where('type', LeaveType::PAID->value)
            ->whereYear('start_date', $year)
            ->whereIn('status', [HrWorkflowStatus::APPROVED->value, HrWorkflowStatus::PENDING->value])
            ->selectRaw('employee_id, status, SUM(days) as total')
            ->groupBy('employee_id', 'status')
            ->toBase()
            ->get()
            ->groupBy('employee_id');

        return Employee::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', EmployeeStatus::TERMINATED->value)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Employee $employee) use ($sums, $year) {
                $rows = $sums->get($employee->id, collect());
                $total = fn (HrWorkflowStatus $status) => (int) ($rows->firstWhere('status', $status->value)?->total ?? 0);

                return ['employee' => EmployeeResource::summary($employee)] + $this->balance(
                    $year,
                    (int) $employee->annual_leave_days,
                    $total(HrWorkflowStatus::APPROVED),
                    $total(HrWorkflowStatus::PENDING),
                );
            })
            ->values()
            ->all();
    }

    /**
     * Formule unique du solde : le reste disponible ne déduit que les jours
     * approuvés — les jours en attente sont signalés, pas encore consommés.
     *
     * @return array{year: int, entitlement: int, taken: int, pending: int, remaining: int}
     */
    private function balance(int $year, int $entitlement, int $taken, int $pending): array
    {
        return [
            'year' => $year,
            'entitlement' => $entitlement,
            'taken' => $taken,
            'pending' => $pending,
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
            ->with(['manager', 'currentLeaves', 'candidature.jobOffer', 'activeContract'])
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
