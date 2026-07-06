<?php

namespace App\Contracts;

/**
 * Frontière vers le fournisseur de paiement mobile money. Implémentée par
 * WaveService — permet aux consommateurs (SubscriptionService) de dépendre
 * d'un contrat plutôt que du SDK/HTTP client Wave concret.
 */
interface PaymentGatewayInterface
{
    /**
     * @param  array{amount: string, currency: string, successUrl: string, errorUrl: string, clientReference?: string}  $params
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $params): array;

    /**
     * @return array<string, mixed>
     */
    public function getCheckoutSession(string $sessionId): array;
}
