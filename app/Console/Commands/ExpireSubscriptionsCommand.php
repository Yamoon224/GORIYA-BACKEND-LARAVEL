<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;
use Illuminate\Console\Command;

/**
 * Passe en EXPIRED les abonnements dont la date de fin est dépassée — planifiée
 * chaque jour (routes/console.php) et déclenchable par cron HTTP via
 * GET /cron/subscriptions/expire.
 */
class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire-overdue';

    protected $description = 'Expire les abonnements actifs dont la date de fin est dépassée';

    public function handle(UserSubscriptionRepositoryInterface $subscriptions): int
    {
        $count = $subscriptions->expireOverdue();

        $this->info("{$count} abonnement(s) expiré(s).");

        return self::SUCCESS;
    }
}
