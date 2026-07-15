<?php

namespace App\Enums;

enum CandidateAssessmentStatus: string
{
    case PENDING = 'PENDING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
