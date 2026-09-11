<?php

namespace App\Enums;

/**
 * Suivi d'un entretien de recrutement. Distinct d'InterviewStatus, qui
 * concerne les simulations d'entretien IA des candidats.
 */
enum RecruitmentInterviewStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case NO_SHOW = 'NO_SHOW';
}
