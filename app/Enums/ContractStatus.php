<?php

namespace App\Enums;

/**
 * Cycle de vie d'un contrat de travail.
 *
 * DRAFT → ACTIVE (mise en vigueur) → ENDED (arrivé à terme ou remplacé par un
 * renouvellement / un avenant) ou TERMINATED (rompu avant terme). Un employé n'a
 * jamais plus d'un contrat ACTIVE : voir EmployeeContractService::activate().
 */
enum ContractStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case ENDED = 'ENDED';
    case TERMINATED = 'TERMINATED';
}
