<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\HrWorkflowStatus;
use App\Enums\LeaveType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Congé d'un employé. `days` compte les jours ouvrés de la période, calculés à
 * la création (EmployeeLeaveService::businessDays) — la valeur ne dépend donc
 * pas d'un recalcul ultérieur si le calendrier change.
 */
class EmployeeLeave extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'start_date',
        'end_date',
        'days',
        'reason',
        'status',
        'created_by',
        'decided_by',
        'decided_at',
        'decision_comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LeaveType::class,
            'status' => HrWorkflowStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'integer',
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
