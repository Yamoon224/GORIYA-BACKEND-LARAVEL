<?php

namespace App\Services;

use App\Contracts\HostedCheckoutGatewayInterface;
use App\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client HTTP fin pour la passerelle de paiement mobile money Wave
 * (Côte d'Ivoire, Sénégal). Restauré depuis l'historique git — avait été
 * retiré au profit de Kkiapay, réintégré en parallèle pour une future
 * activation (voir config('services.payment.enabled_gateways')). Pas
 * d'entité DB propre : chaque tentative est tracée via le modèle
 * Transaction par SubscriptionService.
 *
 * Wave fonctionne par session hébergée (createCheckoutSession() puis
 * redirection vers wave_launch_url), contrairement au widget client de
 * Kkiapay — voir HostedCheckoutGatewayInterface.
 */
class WaveService implements HostedCheckoutGatewayInterface, PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.wave.com';

    public function createCheckoutSession(array $params): array
    {
        $body = [
            'amount' => (string) $params['amount'],
            'currency' => $params['currency'],
            'success_url' => $params['successUrl'],
            'error_url' => $params['errorUrl'],
        ];

        if (! empty($params['clientReference'])) {
            $body['client_reference'] = $params['clientReference'];
        }

        $session = $this->request('post', '/v1/checkout/sessions', $body);

        return [
            'sessionId' => (string) ($session['id'] ?? ''),
            'checkoutUrl' => (string) ($session['wave_launch_url'] ?? ''),
        ];
    }

    public function verifyTransaction(string $transactionId): array
    {
        $session = $this->request('get', "/v1/checkout/sessions/{$transactionId}");

        return [
            ...$session,
            'status' => $this->mapPaymentStatus($session['payment_status'] ?? null),
        ];
    }

    public function refundTransaction(string $transactionId): array
    {
        return $this->request('post', "/v1/checkout/sessions/{$transactionId}/refund");
    }

    /**
     * Wave utilise payment_status ("succeeded"|"cancelled"|"processing") —
     * normalisé vers le vocabulaire SUCCESS/FAILED/PENDING partagé avec
     * Kkiapay/Stripe pour que SubscriptionService reste agnostique du
     * gateway.
     */
    private function mapPaymentStatus(?string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'succeeded' => 'SUCCESS',
            'processing' => 'PENDING',
            default => 'FAILED',
        };
    }

    private function apiKey(): string
    {
        $key = config('services.wave.key');

        if (! $key) {
            abort(500, 'WAVE_API_KEY non configurée');
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $request = Http::withToken($this->apiKey())->acceptJson();

        /** @var Response $response */
        $response = $body !== null
            ? $request->{$method}(self::BASE_URL.$path, $body)
            : $request->{$method}(self::BASE_URL.$path);

        if ($response->failed()) {
            abort(502, $response->json('message') ?? "Wave API error {$response->status()}");
        }

        return $response->json() ?? [];
    }
}
