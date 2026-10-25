<?php

namespace App\Console\Commands;

use App\Contracts\SmsGatewayInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Test manuel de tous les services applicatifs qui envoient un SMS —
 * contrairement à sms:test (qui ne teste que la passerelle MTarget nue),
 * cette commande rejoue le texte exact produit par chacun des deux
 * producteurs de SMS du backend : OtpService::smsMessage() (vérification de
 * compte / réinitialisation de mot de passe) et NotificationService::maybeSms()
 * (notification, réservée aux forfaits `notification_level: ELEVE`).
 *
 * Volontairement sans effet de bord métier (aucune écriture OtpCode/
 * Notification, aucun email) : seul le canal SMS est exercé, vers un numéro
 * de test fixe, directement depuis le serveur réel (IP sortante/liste
 * blanche MTarget dépendent de la machine).
 */
class SmsTestAllServicesCommand extends Command
{
    private const DEFAULT_TEST_PHONE = '0565855202';

    protected $signature = 'sms:test-services {--phone='.self::DEFAULT_TEST_PHONE.' : Numéro de test (local ou international)}';

    protected $description = 'Envoie un SMS de test pour chaque service applicatif utilisant l\'envoi SMS (OTP, notifications)';

    public function handle(SmsGatewayInterface $sms): int
    {
        $phone = (string) $this->option('phone');

        $this->showOutboundIp();
        $this->line("Numéro de test : {$phone}");

        $scenarios = [
            'OtpService — vérification de compte' =>
                'GORIYA : votre code pour vérifier votre compte est 000000. Valable 10 minutes.',
            'OtpService — réinitialisation de mot de passe' =>
                'GORIYA : votre code pour réinitialiser votre mot de passe est 000000. Valable 10 minutes.',
            'NotificationService — notification (forfait ELEVE)' =>
                mb_strimwidth('Test Goriya : notification de test — notification_level ELEVE. Ignorer ce message.', 0, 300, '…'),
        ];

        $failures = 0;

        foreach ($scenarios as $label => $message) {
            $ok = $sms->send($phone, $message);

            if ($ok) {
                $this->info("[OK]   {$label}");
            } else {
                $failures++;
                $this->error("[ECHEC] {$label} — ".($this->lastLoggedReason() ?? 'voir storage/logs/laravel.log pour le détail.'));
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function showOutboundIp(): void
    {
        try {
            $ip = trim((string) Http::timeout(5)->get('https://api.ipify.org')->body());
            $this->line("IP sortante de ce serveur : {$ip}");
        } catch (Throwable) {
            $this->warn('IP sortante : impossible à déterminer (api.ipify.org injoignable).');
        }
    }

    private function lastLoggedReason(): ?string
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'r');
        $size = filesize($path);
        fseek($handle, -min($size, 65536), SEEK_END);
        $tail = stream_get_contents($handle);
        fclose($handle);

        $lines = array_filter(explode("\n", $tail), fn (string $line) => str_contains($line, 'SMS MTarget'));

        return $lines === [] ? null : trim(end($lines));
    }
}
