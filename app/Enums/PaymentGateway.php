<?php

namespace App\Enums;

enum PaymentGateway: string
{
    case KKIAPAY = 'kkiapay';
    case WAVE = 'wave';
    case STRIPE = 'stripe';
}
