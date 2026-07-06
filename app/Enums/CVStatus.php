<?php

namespace App\Enums;

enum CVStatus: string
{
    case ANALYZING = 'ANALYZING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
