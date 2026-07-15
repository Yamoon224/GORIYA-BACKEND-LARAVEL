<?php

namespace App\Enums;

enum SurveyStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case CLOSED = 'CLOSED';
}
