<?php

namespace App\Console\Commands;

use App\Services\CallSessionService;
use Illuminate\Console\Command;

/**
 * Termine les sessions GORIYA Meet dont l'heure est passée — planifiée toutes
 * les cinq minutes, voir routes/console.php. La règle (fin prévue + marge) vit
 * dans CallSession::expiresAt() ; la lecture d'une liste d'appels l'applique
 * aussi, pour que le statut reste juste même sans cron.
 */
class CloseExpiredCallsCommand extends Command
{
    protected $signature = 'calls:close-expired';

    protected $description = "Termine les sessions GORIYA Meet dont l'heure est passée";

    public function handle(CallSessionService $calls): int
    {
        $count = $calls->closeExpired();

        $this->info("{$count} session(s) terminée(s).");

        return self::SUCCESS;
    }
}
