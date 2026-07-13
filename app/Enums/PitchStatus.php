<?php

namespace App\Enums;

enum PitchStatus: string
{
    case DRAFT = 'DRAFT';
    case PROCESSING = 'PROCESSING';
    case READY = 'READY';
    case FAILED = 'FAILED';
}
