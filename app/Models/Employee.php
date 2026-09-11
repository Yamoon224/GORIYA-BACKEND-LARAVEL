<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HrWorkflowStatus;
use App\Enums\JobType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Fiche d'un collaborateur, tenue par le service RH de l'entreprise.
 *
 * Deux origines : une embauche à l'issue d'une candidature Goriya
 * (`candidature_id` et `user_id` renseignés, fiche pré-remplie depuis le compte
 * du candidat et l'offre), ou une saisie manuelle. Dans les deux cas l'employé
 * n'a pas besoin de se connecter : ses congés et demandes sont saisis par les RH.
 */
class Employee extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'manager_id',
        'candidature_id',
        'user_id',
        'matricule',
        'first_name',
        'last_name',
        'gender',
        'birth_date',
        'email',
        'phone',
        'address',
        'job_title',
        'department',
        'contract_type',
        'hire_date',
        'contract_end_date',
        'salary',
        'annual_leave_days',
        'status',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'contract_type' => JobType::class,
            'birth_date' => 'date',
            'hire_date' => 'date',
            'contract_end_date' => 'date',
            'salary' => 'integer',
            'annual_leave_days' => 'integer',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Candidature Goriya d'où provient l'embauche, le cas échéant. */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    /** Compte Goriya du candidat embauché, le cas échéant. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    /** Congés approuvés couvrant la date du jour : alimente « en congé aujourd'hui ». */
    public function currentLeaves(): HasMany
    {
        $today = now()->toDateString();

        return $this->hasMany(EmployeeLeave::class)
            ->where('status', HrWorkflowStatus::APPROVED->value)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today);
    }

    public function hrRequests(): HasMany
    {
        return $this->hasMany(HrRequest::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    /** Contrat en vigueur — jamais plus d'un, garanti par EmployeeContractService. */
    public function activeContract(): HasOne
    {
        return $this->hasOne(EmployeeContract::class)->where('status', ContractStatus::ACTIVE->value);
    }
}
