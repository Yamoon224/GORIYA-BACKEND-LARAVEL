<?php

namespace App\Enums;

enum JobStatus: string
{
    case ACTIVE = 'ACTIVE';
    case CLOSED = 'CLOSED';
    case DRAFT = 'DRAFT';
}
