<?php

namespace App\Enums;

enum MatchingStatus: string
{
    case NOUVEAU = 'NOUVEAU';
    case EN_COURS = 'EN_COURS';
    case FINALISE = 'FINALISE';
}
