<?php

namespace App\Enums;

enum InterviewStatus: string
{
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';
    case SCHEDULED = 'SCHEDULED';
}
