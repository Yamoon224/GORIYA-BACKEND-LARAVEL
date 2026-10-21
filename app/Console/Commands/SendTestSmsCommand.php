<?php

namespace App\Console\Commands;

use App\Contracts\SmsGatewayInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Test manuel du canal SMS (MTarget) — utile pour vérifier une configuration
 * (identifiants, serviceid, liste blanche IP côté MTarget) depuis
 * l'environnement réel (le certificat TLS et l'IP sortante diffèrent d'une
 * machine à l'autre). Affiche l'IP sortante du serveur (celle à transmettre
 * à MTarget pour la liste blanche) et, en cas d'échec, la dernière raison
 * journalisée par MtargetSmsService::send(), qui ne renvoie qu'un booléen.
 */
class SendTestSmsCommand extends Command
{
    protected $signature = 'sms:test {phone* : Un ou plusieurs numéros (locaux ou internationaux)} {--message= : Message personnalisé}';

    protected $description = 'Envoie un SMS de test via MTarget à un ou plusieurs numéros';

    public function handle(SmsGatewayInterface $sms): int
    {
        $this->showOutboundIp();

        $message = $this->option('message') ?: 'GORIYA : test SMS API MTarget. Ignorer ce message.';
        $failures = 0;

        foreach ($this->argument('phone') as $phone) {
            $ok = $sms->send($phone, $message);

            if ($ok) {
                $this->info("{$phone} : envoyé.");
            } else {
                $failures++;
                $this->error("{$phone} : échec — ".($this->lastLoggedReason() ?? 'voir storage/logs/laravel.log pour le détail.'));
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * C'est cette IP qu'il faut communiquer à MTarget (back-office ou
     * support@mtarget.fr) pour la liste blanche — elle diffère de la
     * machine de dev.
     */
    private function showOutboundIp(): void
    {
        try {
            $ip = trim((string) Http::timeout(5)->get('https://api.ipify.org')->body());
            $this->line("IP sortante de ce serveur : {$ip}");
        } catch (Throwable) {
            $this->warn('IP sortante : impossible à déterminer (api.ipify.org injoignable).');
        }
    }

    /**
     * Relit la dernière ligne pertinente du log applicatif plutôt que de
     * laisser l'utilisateur l'y chercher — storage/logs/laravel.log grossit
     * vite en prod.
     */
    private function lastLoggedReason(): ?string
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return null;
        }

        // Ne lit que la fin du fichier (il peut être volumineux en prod) :
        // largement suffisant pour retrouver la ligne qu'on vient d'écrire.
        $handle = fopen($path, 'r');
        $size = filesize($path);
        fseek($handle, -min($size, 65536), SEEK_END);
        $tail = stream_get_contents($handle);
        fclose($handle);

        $lines = array_filter(explode("\n", $tail), fn (string $line) => str_contains($line, 'SMS MTarget'));

        return $lines === [] ? null : trim(end($lines));
    }
}
