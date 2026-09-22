<?php

namespace App\Enums;

enum InfluencerPayoutStatus: string
{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case CANCELLED = 'CANCELLED';
}
