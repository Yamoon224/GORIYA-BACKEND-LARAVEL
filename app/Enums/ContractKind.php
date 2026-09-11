<?php

namespace App\Enums;

/**
 * Nature d'un contrat dans l'historique d'un employé. Un renouvellement ou un
 * avenant référence toujours le contrat qu'il prolonge ou modifie (`parent_id`).
 */
enum ContractKind: string
{
    case INITIAL = 'INITIAL';
    case RENEWAL = 'RENEWAL';
    case AMENDMENT = 'AMENDMENT';
}
