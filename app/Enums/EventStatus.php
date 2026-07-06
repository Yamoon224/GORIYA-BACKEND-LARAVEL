<?php

namespace App\Enums;

enum EventStatus: string
{
    case CONFIRMED = 'CONFIRMED';
    case PENDING = 'PENDING';
    case CANCELLED = 'CANCELLED';
}
