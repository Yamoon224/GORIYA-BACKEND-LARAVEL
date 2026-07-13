<?php

namespace App\Services;

use App\Contracts\HostedCheckoutGatewayInterface;
use App\Contracts\PaymentGatewayInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Résout le gateway de paiement actif par nom (Kkiapay/Wave/Stripe) à partir
 * de config('services.payment.enabled_gateways'). Bindée comme implémentation
 * par défaut de PaymentGatewayInterface — délègue alors au gateway par défaut
 * (config('services.payment.default_gateway')) pour rester compatible avec
 * tout futur consommateur qui n'a pas besoin de sélectionner un gateway
 * explicitement.
 *
 * SubscriptionService dépend directement de cette classe (pas seulement de
 * l'interface) car il a besoin de resolve() pour choisir le gateway demandé
 * par le frontend au checkout.
 */
class PaymentGatewayManager implements PaymentGatewayInterface
{
    /** @var array<string, class-string<PaymentGatewayInterface>> */
    private const GATEWAY_CLASSES = [
        'kkiapay' => KkiapayService::class,
        'wave' => WaveService::class,
        'stripe' => StripeService::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function resolve(?string $name = null): PaymentGatewayInterface
    {
        $name = $name ?? $this->defaultGatewayName();

        if (! in_array($name, $this->enabledGateways(), true)) {
            abort(400, "Gateway de paiement '{$name}' non activé");
        }

        $class = self::GATEWAY_CLASSES[$name] ?? null;
        if (! $class) {
            abort(400, "Gateway de paiement '{$name}' inconnu");
        }

        return $this->container->make($class);
    }

    public function supportsHostedCheckout(string $name): bool
    {
        return $this->resolve($name) instanceof HostedCheckoutGatewayInterface;
    }

    /**
     * @return array<int, string>
     */
    public function enabledGateways(): array
    {
        return config('services.payment.enabled_gateways', ['kkiapay']);
    }

    public function defaultGatewayName(): string
    {
        return config('services.payment.default_gateway', 'kkiapay');
    }

    public function verifyTransaction(string $transactionId): array
    {
        return $this->resolve()->verifyTransaction($transactionId);
    }

    public function refundTransaction(string $transactionId): array
    {
        return $this->resolve()->refundTransaction($transactionId);
    }
}
