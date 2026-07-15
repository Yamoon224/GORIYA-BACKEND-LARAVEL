<?php

namespace App\Enums;

enum CallSessionStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case ACTIVE = 'ACTIVE';
    case ENDED = 'ENDED';
}
