<?php

namespace App\Enums;

/**
 * Période de paie : DRAFT (calculée, ajustable, recalculable) → VALIDATED
 * (bulletins figés, avances marquées comme déduites) → PAID (virements faits).
 * Une paie validée mais pas encore payée peut être rouverte si c'est la
 * dernière période.
 */
enum PayrollRunStatus: string
{
    case DRAFT = 'DRAFT';
    case VALIDATED = 'VALIDATED';
    case PAID = 'PAID';
}
