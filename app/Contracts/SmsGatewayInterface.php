<?php

namespace App\Contracts;

/**
 * Frontière vers l'envoi de SMS — implémentée par MtargetSmsService. Comme
 * PushNotificationServiceInterface, un SMS est un canal complémentaire :
 * l'absence de configuration ou un échec d'envoi ne doivent jamais faire
 * échouer l'action qui le déclenche (OTP, réinitialisation, notification…).
 * Les appelants critiques (OTP) gardent l'email comme canal de référence.
 */
interface SmsGatewayInterface
{
    public function send(string $msisdn, string $message, ?string $sender = null): bool;
}
