<?php

namespace App\Enums;

enum MailCampaignRecipientStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
