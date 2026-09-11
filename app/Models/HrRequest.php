<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\HrRequestType;
use App\Enums\HrWorkflowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande RH d'un employé : attestation, avance sur salaire, formation,
 * matériel… `amount` (FCFA) n'a de sens que pour une avance sur salaire.
 */
class HrRequest extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'subject',
        'description',
        'amount',
        'status',
        'created_by',
        'decided_by',
        'decided_at',
        'decision_comment',
        // Avance sur salaire : bulletin validé sur lequel elle a été retenue.
        'payslip_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => HrRequestType::class,
            'status' => HrWorkflowStatus::class,
            'amount' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
