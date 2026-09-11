<?php

namespace App\Enums;

enum MailCampaignStatus: string
{
    case DRAFT = 'draft';
    case SENDING = 'sending';
    case SENT = 'sent';
    case FAILED = 'failed';
}
