<?php

namespace App\Enums;

enum PromoCampaignStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
    case ENDED = 'ENDED';
}
