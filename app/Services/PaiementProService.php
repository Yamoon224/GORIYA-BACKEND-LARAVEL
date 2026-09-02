<?php

namespace App\Services;

use App\Contracts\HostedCheckoutGatewayInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;

/**
 * Passerelle Paiement Pro (agrégateur Côte d'Ivoire : Mobile Money OM/MTN/Moov,
 * carte bancaire, PayPal). Flux "session hébergée" comme Wave/Stripe : le
 * serveur initialise la transaction (init REST JSON), reçoit une URL de
 * paiement hébergée et y redirige l'utilisateur — voir
 * HostedCheckoutGatewayInterface.
 *
 * Contrairement à Wave/Stripe, Paiement Pro n'expose pas d'appel de
 * consultation de statut : le résultat définitif est poussé de façon
 * asynchrone par une notification serveur-à-serveur
 * (PaiementProWebhookController) qui met à jour la Transaction. verifyTransaction()
 * se contente donc de relire le statut ainsi enregistré.
 */
class PaiementProService implements HostedCheckoutGatewayInterface, PaymentGatewayInterface
{
    private const INIT_PATH = '/webservice/onlinepayment/init/curl-init.php';

    /** Doit rester aligné sur la route de PaiementProWebhookController. */
    public const NOTIFICATION_PATH = '/webhooks/paiementpro';

    /**
     * @param  array{amount: int|string, currency: string, successUrl: string, errorUrl: string, clientReference?: string, customerEmail?: string, customerFirstName?: string, customerLastName?: string, customerPhone?: string, userId?: string, planId?: string}  $params
     * @return array{sessionId: string, checkoutUrl: string}
     */
    public function createCheckoutSession(array $params): array
    {
        $merchantId = config('services.paiementpro.merchant_id');
        if (! $merchantId) {
            abort(500, 'PAIEMENTPRO_MERCHANT_ID non configuré');
        }

        $reference = $params['clientReference'] ?? (string) (int) round(microtime(true) * 1000);
        $token = config('services.paiementpro.notification_token');

        // Pas de préfixe /api : bootstrap/app.php déclare apiPrefix: '' —
        // la route est servie à la racine (voir routes/api.php).
        $notificationUrl = rtrim((string) config('app.url'), '/').self::NOTIFICATION_PATH;
        if ($token) {
            $notificationUrl .= '?token='.urlencode((string) $token);
        }

        // On encode notre référence dans le returnURL pour ne pas dépendre des
        // paramètres que Paiement Pro y ajoute (non documentés de façon fiable).
        $returnUrl = $params['successUrl'].(str_contains($params['successUrl'], '?') ? '&' : '?').'ref='.urlencode($reference);

        $body = [
            'merchantId' => $merchantId,
            'amount' => (int) round((float) $params['amount']),
            'description' => 'Abonnement GORIYA',
            'countryCurrencyCode' => (string) config('services.paiementpro.currency_code', '952'),
            'referenceNumber' => $reference,
            'customerEmail' => $params['customerEmail'] ?? 'client@goriya.net',
            'customerFirstName' => $params['customerFirstName'] ?? 'Client',
            'customerLastname' => $params['customerLastName'] ?? 'GORIYA',
            'customerPhoneNumber' => $params['customerPhone'] ?? config('services.paiementpro.default_phone'),
            'notificationURL' => $notificationUrl,
            'returnURL' => $returnUrl,
            'returnContext' => json_encode([
                'userId' => $params['userId'] ?? null,
                'planId' => $params['planId'] ?? null,
            ]),
        ];

        $channel = config('services.paiementpro.channel');
        if ($channel) {
            $body['channel'] = $channel;
        }

        $response = Http::acceptJson()
            ->asJson()
            ->post(rtrim((string) config('services.paiementpro.base_url'), '/').self::INIT_PATH, $body);

        $data = $response->json() ?? [];

        if ($response->failed() || ($data['success'] ?? false) !== true || empty($data['url'])) {
            abort(502, $data['message'] ?? "Échec de l'initialisation Paiement Pro (HTTP {$response->status()})");
        }

        // On identifie la Transaction par NOTRE referenceNumber, pas par le
        // `sessionid` éphémère de Paiement Pro : c'est referenceNumber qui est
        // repris dans la notification serveur-à-serveur et dans le returnURL.
        return [
            'sessionId' => $reference,
            'checkoutUrl' => (string) $data['url'],
        ];
    }

    /**
     * Pas d'API de statut Paiement Pro : on relit la Transaction mise à jour
     * par la notification serveur-à-serveur. Tant que la notification n'est pas
     * arrivée, le statut reste PENDING (le frontend re-tente).
     *
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $transactionId): array
    {
        $transaction = Transaction::query()
            ->where('gateway_transaction_id', $transactionId)
            ->first();

        $status = match ($transaction?->status) {
            TransactionStatus::SUCCESS => 'SUCCESS',
            TransactionStatus::FAILED, TransactionStatus::REFUNDED => 'FAILED',
            default => 'PENDING',
        };

        // On repasse le payload déjà enregistré par la notification pour que la
        // ré-écriture de raw_payload par SubscriptionService ne le perde pas.
        return [
            ...((array) ($transaction?->raw_payload ?? [])),
            'reference' => $transactionId,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refundTransaction(string $transactionId): array
    {
        abort(501, 'Le remboursement Paiement Pro doit être effectué manuellement depuis le dashboard marchand');
    }
}
