<?php

namespace App\Enums;

/**
 * Situation administrative d'un employé. « En congé » n'en fait pas partie :
 * c'est un état du jour, déduit des congés approuvés (voir
 * EmployeeService::isOnLeaveToday), pas un statut que l'on saisit.
 */
enum EmployeeStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PROBATION = 'PROBATION';
    case SUSPENDED = 'SUSPENDED';
    case TERMINATED = 'TERMINATED';
}
