<?php

namespace App\Enums;

enum PromoRedemptionStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';
}
