<?php

namespace App\Contracts;

/**
 * Frontière optionnelle pour les gateways à flux "session hébergée" (le
 * serveur crée une session de paiement et redirige l'utilisateur vers une
 * page hébergée par le fournisseur), par opposition au flux "widget client"
 * de Kkiapay où seul verifyTransaction()/refundTransaction() (voir
 * PaymentGatewayInterface) sont nécessaires côté serveur.
 *
 * Implémentée par WaveService, StripeService et PaiementProService.
 * PaymentGatewayManager teste `instanceof HostedCheckoutGatewayInterface` pour
 * savoir si un gateway nécessite ce flux avant d'appeler checkout().
 *
 * Les clés `customer*` / `userId` / `planId` ne sont utilisées que par
 * PaiementProService (Paiement Pro exige les coordonnées du payeur à
 * l'initialisation) — Wave et Stripe les ignorent.
 */
interface HostedCheckoutGatewayInterface
{
    /**
     * @param  array{amount: int|string, currency: string, successUrl: string, errorUrl: string, clientReference?: string, customerEmail?: ?string, customerFirstName?: string, customerLastName?: string, customerPhone?: ?string, userId?: string, planId?: string}  $params
     * @return array{sessionId: string, checkoutUrl: string}
     */
    public function createCheckoutSession(array $params): array;
}
