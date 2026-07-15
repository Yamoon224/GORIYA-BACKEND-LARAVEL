<?php

namespace App\Enums;

enum ChatMessageRole: string
{
    case USER = 'USER';
    case ASSISTANT = 'ASSISTANT';
}
