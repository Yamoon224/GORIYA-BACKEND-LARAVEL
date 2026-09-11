<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Paramètres de paie d'une entreprise — voir PayrollSettingsService. */
class PayrollSetting extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'preset',
        'contributions',
        'tax_brackets',
        'tax_label',
        'taxable_abatement_percent',
        'deduct_employee_contributions',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contributions' => 'array',
            'tax_brackets' => 'array',
            'taxable_abatement_percent' => 'float',
            'deduct_employee_contributions' => 'boolean',
        ];
    }
}
