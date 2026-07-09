<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use Kkiapay\Kkiapay;

/**
 * Client fin pour la passerelle de paiement mobile money Kkiapay, basé sur le
 * SDK Admin officiel (kkiapay/kkiapay-php). Pas d'entité DB propre.
 */
class KkiapayService implements PaymentGatewayInterface
{
    private Kkiapay $client;

    public function __construct()
    {
        $publicKey = config('services.kkiapay.public_key');
        $privateKey = config('services.kkiapay.private_key');
        $secret = config('services.kkiapay.secret');

        if (! $publicKey || ! $privateKey || ! $secret) {
            abort(500, 'Clés Kkiapay non configurées (KKIAPAY_PUBLIC_KEY / KKIAPAY_PRIVATE_KEY / KKIAPAY_SECRET)');
        }

        $this->client = new Kkiapay($publicKey, $privateKey, $secret, (bool) config('services.kkiapay.sandbox'));
    }

    public function verifyTransaction(string $transactionId): array
    {
        return $this->toArray($this->client->verifyTransaction($transactionId));
    }

    public function refundTransaction(string $transactionId): array
    {
        return $this->toArray($this->client->refundTransaction($transactionId));
    }

    /**
     * Le SDK renvoie un stdClass (ou, après 3 tentatives infructueuses, un
     * simple code HTTP entier) — on normalise toujours vers un tableau.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $response): array
    {
        $decoded = json_decode(json_encode($response), true);

        return is_array($decoded) ? $decoded : ['status' => 'FAILED'];
    }
}
