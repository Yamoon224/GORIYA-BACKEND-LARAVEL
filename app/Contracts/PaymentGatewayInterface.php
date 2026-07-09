<?php

namespace App\Contracts;

/**
 * Frontière vers le fournisseur de paiement mobile money. Implémentée par
 * KkiapayService — permet aux consommateurs (SubscriptionService) de dépendre
 * d'un contrat plutôt que du SDK/HTTP client Kkiapay concret.
 *
 * Kkiapay initie le paiement côté client (widget JS avec la clé publique) —
 * le serveur n'a donc qu'à vérifier/rembourser une transaction déjà effectuée,
 * contrairement au flux Wave précédent (création de session hébergée).
 */
interface PaymentGatewayInterface
{
    /**
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $transactionId): array;

    /**
     * @return array<string, mixed>
     */
    public function refundTransaction(string $transactionId): array;
}
