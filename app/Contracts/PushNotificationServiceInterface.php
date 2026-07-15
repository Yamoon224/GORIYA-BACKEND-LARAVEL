<?php

namespace App\Contracts;

/**
 * Frontière vers l'envoi de notifications push (FCM) — implémentée par
 * FcmPushNotificationService. Contrairement aux services de paiement/vidéo
 * qui échouent explicitement si mal configurés, un push est un canal
 * "bonus" en plus de la notification in-app déjà créée par
 * NotificationService : l'absence de configuration ne doit jamais faire
 * échouer la requête HTTP qui a déclenché la notification.
 */
interface PushNotificationServiceInterface
{
    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void;
}
