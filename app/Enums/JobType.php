<?php

namespace App\Enums;

enum JobType: string
{
    case CDI = 'CDI';
    case CDD = 'CDD';
    case STAGE = 'STAGE';
    case ALTERNANCE = 'ALTERNANCE';
    case FREELANCE = 'FREELANCE';
    case TEMPS_PARTIEL = 'TEMPS_PARTIEL';
}
