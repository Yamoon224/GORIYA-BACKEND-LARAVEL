<?php

namespace App\Enums;

enum CandidatureStatus: string
{
    case EN_ATTENTE = 'EN_ATTENTE';
    case EN_COURS = 'EN_COURS';
    case APPROUVEE = 'APPROUVEE';
    case REJETEE = 'REJETEE';
}
