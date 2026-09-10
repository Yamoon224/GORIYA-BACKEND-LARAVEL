<?php

namespace App\Enums;

/**
 * Nature d'un congé. Seul PAID est décompté du solde annuel : les autres
 * (maladie, maternité…) relèvent de droits distincts.
 */
enum LeaveType: string
{
    case PAID = 'PAID';
    case SICK = 'SICK';
    case MATERNITY = 'MATERNITY';
    case PATERNITY = 'PATERNITY';
    case FAMILY_EVENT = 'FAMILY_EVENT';
    case UNPAID = 'UNPAID';
    case OTHER = 'OTHER';
}
