<?php

namespace App\Enums;

/** Nature d'une demande adressée au service RH par (ou pour) un employé. */
enum HrRequestType: string
{
    case WORK_CERTIFICATE = 'WORK_CERTIFICATE';
    case SALARY_CERTIFICATE = 'SALARY_CERTIFICATE';
    case SALARY_ADVANCE = 'SALARY_ADVANCE';
    case TRAINING = 'TRAINING';
    case EQUIPMENT = 'EQUIPMENT';
    case SCHEDULE_CHANGE = 'SCHEDULE_CHANGE';
    case OTHER = 'OTHER';
}
