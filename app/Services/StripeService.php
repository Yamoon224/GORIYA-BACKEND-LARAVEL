<?php

namespace App\Services;

use App\Contracts\HostedCheckoutGatewayInterface;
use App\Contracts\PaymentGatewayInterface;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Passerelle Stripe (cartes bancaires) pour la diaspora et les utilisateurs
 * internationaux — Kkiapay/Wave couvrent le mobile money local, Stripe
 * couvre les paiements par carte hors Afrique de l'Ouest. Flux "session
 * hébergée" comme Wave (Stripe Checkout), pas de widget client.
 */
class StripeService implements HostedCheckoutGatewayInterface, PaymentGatewayInterface
{
    private StripeClient $client;

    public function __construct()
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            abort(500, 'Clé Stripe non configurée (STRIPE_SECRET_KEY)');
        }

        $this->client = new StripeClient($secret);
    }

    public function createCheckoutSession(array $params): array
    {
        $session = $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($params['currency']),
                    'unit_amount' => $this->toSmallestUnit((float) $params['amount'], $params['currency']),
                    'product_data' => ['name' => 'Abonnement GORIYA'],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $params['successUrl'],
            'cancel_url' => $params['errorUrl'],
            'client_reference_id' => $params['clientReference'] ?? null,
        ]);

        return [
            'sessionId' => $session->id,
            'checkoutUrl' => $session->url ?? '',
        ];
    }

    public function verifyTransaction(string $transactionId): array
    {
        /** @var CheckoutSession $session */
        $session = $this->client->checkout->sessions->retrieve($transactionId);

        return [
            'id' => $session->id,
            'paymentIntent' => $session->payment_intent,
            'status' => $this->mapPaymentStatus($session->payment_status),
        ];
    }

    public function refundTransaction(string $transactionId): array
    {
        $session = $this->client->checkout->sessions->retrieve($transactionId);

        /** @var Refund $refund */
        $refund = $this->client->refunds->create([
            'payment_intent' => $session->payment_intent,
        ]);

        return [
            'id' => $refund->id,
            'status' => $refund->status === 'succeeded' ? 'SUCCESS' : 'FAILED',
        ];
    }

    /**
     * Stripe utilise payment_status ("paid"|"unpaid"|"no_payment_required")
     * — normalisé vers le même vocabulaire SUCCESS/FAILED/PENDING que
     * Kkiapay/Wave pour que SubscriptionService reste agnostique du gateway.
     */
    private function mapPaymentStatus(?string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'paid', 'no_payment_required' => 'SUCCESS',
            'unpaid' => 'PENDING',
            default => 'FAILED',
        };
    }

    /**
     * Stripe attend le montant dans la plus petite unité de la devise (cents
     * pour USD/EUR) — mais le XOF n'a pas de sous-unité. Cette gateway n'est
     * de toute façon destinée qu'aux devises internationales (USD/EUR).
     */
    private function toSmallestUnit(float $amount, string $currency): int
    {
        $zeroDecimalCurrencies = ['XOF', 'XAF', 'JPY'];

        return in_array(strtoupper($currency), $zeroDecimalCurrencies, true)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }
}
