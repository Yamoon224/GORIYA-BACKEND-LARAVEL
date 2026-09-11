<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Traite la file d'attente (QUEUE_CONNECTION=database) sans worker permanent
 * : sur un hébergement mutualisé, `php artisan queue:work` (processus long)
 * n'est pas exécutable. --stop-when-empty fait sortir la commande dès que la
 * file est vide au lieu de tourner en boucle ; --max-time=55 la coupe avant
 * le prochain tick cron même s'il reste des jobs (ils seront repris à la
 * minute suivante) ; withoutOverlapping() évite un double traitement si une
 * exécution dépasse la minute. Utilisé par SendCampaignRecipientJob (voir
 * MailCampaignService::send(), jobs dispatchés avec délai croissant).
 *
 * Un seul cron à créer côté hébergeur (hPanel Hostinger) :
 *   * * * * * php /chemin/vers/backend/artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
