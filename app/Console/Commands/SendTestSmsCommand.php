<?php

namespace App\Console\Commands;

use App\Contracts\SmsGatewayInterface;
use Illuminate\Console\Command;

/**
 * Test manuel du canal SMS (MTarget) — utile pour vérifier une configuration
 * (identifiants, serviceid, liste blanche IP côté MTarget) depuis
 * l'environnement réel (le certificat TLS et l'IP sortante diffèrent d'une
 * machine à l'autre). Le détail d'un échec (ex: "ip address not allowed")
 * est dans storage/logs/laravel.log — voir MtargetSmsService::send(), qui ne
 * renvoie qu'un booléen.
 */
class SendTestSmsCommand extends Command
{
    protected $signature = 'sms:test {phone* : Un ou plusieurs numéros (locaux ou internationaux)} {--message= : Message personnalisé}';

    protected $description = 'Envoie un SMS de test via MTarget à un ou plusieurs numéros';

    public function handle(SmsGatewayInterface $sms): int
    {
        $message = $this->option('message') ?: 'GORIYA : test SMS API MTarget. Ignorer ce message.';
        $failures = 0;

        foreach ($this->argument('phone') as $phone) {
            $ok = $sms->send($phone, $message);

            if ($ok) {
                $this->info("{$phone} : envoyé.");
            } else {
                $failures++;
                $this->error("{$phone} : échec — voir storage/logs/laravel.log pour le détail (raison MTarget, IP non autorisée, identifiants...).");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
