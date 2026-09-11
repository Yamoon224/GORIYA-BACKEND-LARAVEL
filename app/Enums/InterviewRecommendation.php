<?php

namespace App\Enums;

/** Avis de l'intervieweur à l'issue d'un entretien. */
enum InterviewRecommendation: string
{
    case HIRE = 'HIRE';
    case MAYBE = 'MAYBE';
    case NO_HIRE = 'NO_HIRE';
}
