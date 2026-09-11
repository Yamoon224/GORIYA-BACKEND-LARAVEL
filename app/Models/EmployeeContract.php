<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\ContractKind;
use App\Enums\ContractStatus;
use App\Enums\JobType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contrat de travail d'un employé : contrat initial, renouvellement ou avenant.
 * Les règles (un seul contrat en vigueur, synchronisation de la fiche employé,
 * transitions) vivent dans EmployeeContractService.
 */
class EmployeeContract extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'parent_id',
        'reference',
        'kind',
        'type',
        'job_title',
        'start_date',
        'end_date',
        'trial_end_date',
        'salary',
        'weekly_hours',
        'status',
        'signed_at',
        'termination_date',
        'termination_reason',
        'document_path',
        'document_name',
        'document_uploaded_at',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContractKind::class,
            'type' => JobType::class,
            'status' => ContractStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'trial_end_date' => 'date',
            'signed_at' => 'date',
            'termination_date' => 'date',
            'document_uploaded_at' => 'datetime',
            'salary' => 'integer',
            'weekly_hours' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Contrat prolongé ou modifié par celui-ci. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Renouvellements et avenants établis à partir de ce contrat. */
    public function successors(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
