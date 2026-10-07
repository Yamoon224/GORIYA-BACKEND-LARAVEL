<?php

namespace App\Services;

use App\Contracts\HostedCheckoutGatewayInterface;
use App\Enums\SubscriptionStatus;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\UserSubscriptionResource;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSubscription;
use App\Repositories\Contracts\SubscriptionPlanRepositoryInterface;
use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;
use App\Support\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirroir de backend/src/subscriptions/subscriptions.service.ts. Extrait de
 * SubscriptionsController pour cohérence avec le reste du port.
 *
 * Dépend directement de PaymentGatewayManager (pas seulement de
 * PaymentGatewayInterface) car checkout()/verifyCheckout() doivent choisir
 * explicitement le gateway demandé par le frontend (resolve()), pas juste
 * appeler le gateway par défaut.
 */
class SubscriptionService
{
    /** Marque nos références de paiement (voir newPaymentReference()). */
    private const REFERENCE_PREFIX = 'GRY';

    /** En deçà, une référence tronquée est trop courte pour être rapprochée. */
    private const MIN_REFERENCE_PREFIX_LENGTH = 20;

    public function __construct(
        private readonly SubscriptionPlanRepositoryInterface $subscriptionPlanRepository,
        private readonly UserSubscriptionRepositoryInterface $userSubscriptionRepository,
        private readonly PaymentGatewayManager $paymentGatewayManager,
        private readonly UserFeatureUsageService $userFeatureUsageService,
        private readonly PromoCodeService $promoCodeService,
    ) {}

    public function plans(?string $userType): AnonymousResourceCollection
    {
        return SubscriptionPlanResource::collection($this->subscriptionPlanRepository->findActive($userType));
    }

    /**
     * @return array{enabledGateways: array<int, string>, defaultGateway: string}
     */
    public function paymentGateways(): array
    {
        return [
            'enabledGateways' => $this->paymentGatewayManager->enabledGateways(),
            'defaultGateway' => $this->paymentGatewayManager->defaultGatewayName(),
        ];
    }

    /**
     * Activation directe, SANS paiement. Réservée aux plans gratuits (prix 0),
     * dont l'« Offre gratuite » entreprise : un forfait payant ne peut être
     * activé que par /subscriptions/checkout puis vérification de la
     * transaction, sinon n'importe quel compte authentifié pourrait s'offrir
     * Business+ en appelant cet endpoint avec son propre userId.
     *
     * Le userId reste dans le corps (parité NestJS) mais n'est plus libre :
     * seul son propriétaire — ou un ADMIN — peut l'utiliser.
     */
    public function subscribe(string $userId, string $planId): UserSubscriptionResource
    {
        $actor = auth('api')->user();

        if ($actor && $actor->role !== UserRole::ADMIN && $actor->id !== $userId) {
            abort(403, 'Vous ne pouvez activer un abonnement que pour votre propre compte');
        }

        $plan = $this->subscriptionPlanRepository->find($planId);
        if (! $plan) {
            abort(404, 'Plan non trouvé');
        }

        if (! $plan->isFree()) {
            abort(403, 'Ce plan est payant : son activation passe par le paiement.');
        }

        $sub = $this->performSubscribe($userId, $planId);

        return new UserSubscriptionResource($sub->load('plan'));
    }

    /*
    |----------------------------------------------------------------------
    | MY SUBSCRIPTION — NOTE: userId vient du path, pas du JWT authentifié,
    | limitation héritée du backend NestJS, volontairement préservée.
    |----------------------------------------------------------------------
    */
    public function mySubscription(string $userId): ?UserSubscriptionResource
    {
        $sub = $this->userSubscriptionRepository->findActiveForUser($userId);

        return $sub ? new UserSubscriptionResource($sub) : null;
    }

    public function cancel(string $userId): void
    {
        $this->userSubscriptionRepository->cancelActiveForUser($userId);
    }

    /**
     * Historique des transactions de paiement de l'utilisateur (« Historique
     * des factures » côté entreprise) — une par tentative, y compris échouée.
     */
    public function transactions(string $userId, int $page, int $limit)
    {
        $paginator = Transaction::query()
            ->with('plan')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Transaction $transaction) => (new TransactionResource($transaction))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /**
     * `tier` distingue l'offre gratuite (FREE) d'un forfait payant (PAID) —
     * sans lui, un abonnement gratuit actif rendait `hasSubscription` vrai et
     * ouvrait donc toutes les pages premium côté frontend.
     *
     * @return array{hasSubscription: bool, planName: ?string, status: ?string, planId: ?string, planPrice: ?float, tier: string}
     */
    public function check(string $userId): array
    {
        $sub = $this->userSubscriptionRepository->findActiveForUser($userId);
        $plan = $sub?->plan;

        return [
            'hasSubscription' => (bool) $sub,
            'planName' => $plan?->name,
            'status' => $sub?->status?->value,
            'planId' => $plan?->id,
            'planPrice' => $plan ? (float) $plan->price : null,
            'tier' => $plan?->tier() ?? 'NONE',
        ];
    }

    /**
     * Kkiapay (widget client) : le backend fournit juste montant + référence.
     * Wave/Stripe (session hébergée) : le backend crée la session et renvoie
     * l'URL de redirection. Dans les deux cas, une Transaction PENDING est
     * tracée pour l'audit/la reprise — voir verifyCheckout().
     *
     * @return array<string, mixed>
     */
    public function checkout(array $data): array
    {
        $plan = $this->subscriptionPlanRepository->find($data['planId']);
        if (! $plan) {
            abort(404, 'Plan non trouvé');
        }

        // Réinitialisation de quota (500 XOF) plutôt qu'abonnement : même
        // gateways, montant et suite différents — voir checkoutUsageReset().
        if (($data['purpose'] ?? 'SUBSCRIPTION') === 'USAGE_RESET') {
            return $this->checkoutUsageReset($data, $plan);
        }

        if ((float) $plan->price === 0.0) {
            abort(400, 'Ce plan est gratuit, utilisez /subscribe directement');
        }

        // Périodicité entreprise (1/3/6/12 mois) : le prix catalogue est
        // toujours mensuel, le montant facturé se multiplie par la durée
        // choisie. Les plans USER (Standard/Premium) n'ont pas
        // `available_periods` -> restent fixés à 1 mois quoi qu'on envoie.
        $periodMonths = (int) ($data['periodMonths'] ?? 1);
        if (! in_array($periodMonths, $plan->allowedPeriods(), true)) {
            $periodMonths = 1;
        }

        $gatewayName = $data['gateway'] ?? $this->paymentGatewayManager->defaultGatewayName();
        $currency = $data['currency'] ?? 'XOF';
        $basePrice = (float) $plan->price * $periodMonths;

        // Code promo optionnel : revalidé et recalculé côté serveur, jamais
        // de confiance dans un montant déjà réduit venu du client — voir
        // PromoCodeService::validate()/computeDiscount().
        $promoCode = null;
        $discountAmount = 0.0;
        if (! empty($data['promoCode'])) {
            $validation = $this->promoCodeService->validate($data['promoCode'], $data['userId'], $plan);
            $promoCode = $validation['code'];
            $computed = $this->promoCodeService->computeDiscount($promoCode, $basePrice);
            $discountAmount = $computed['discountAmount'];
            $basePrice = $computed['finalAmount'];
        }

        // XOF n'a pas de sous-unité décimale — le montant doit être un entier.
        $amount = $currency === 'XOF' ? (int) round($basePrice) : $basePrice;
        $clientReference = $this->newPaymentReference();

        if ($this->paymentGatewayManager->supportsHostedCheckout($gatewayName)) {
            /** @var HostedCheckoutGatewayInterface $gateway */
            $gateway = $this->paymentGatewayManager->resolve($gatewayName);
            $session = $gateway->createCheckoutSession([
                'amount' => $amount,
                'currency' => $currency,
                'successUrl' => $data['successUrl'] ?? config('app.url'),
                'errorUrl' => $data['errorUrl'] ?? config('app.url'),
                'clientReference' => $clientReference,
                // Infos client — requises par Paiement Pro, ignorées par Wave/Stripe.
                ...$this->customerDetails($data['userId'], $data['planId'], $data['customerPhone'] ?? null),
            ]);

            $transaction = $this->recordTransaction($data['userId'], $data['planId'], $gatewayName, $session['sessionId'], $amount, $currency, $periodMonths, 'SUBSCRIPTION', null, $promoCode?->id, $discountAmount ?: null);
            if ($promoCode) {
                $this->promoCodeService->createPendingRedemption($promoCode, $data['userId'], $transaction, $amount + $discountAmount, $discountAmount, (float) $amount, $currency);
            }

            return [
                'gateway' => $gatewayName,
                'checkoutUrl' => $session['checkoutUrl'],
                'sessionId' => $session['sessionId'],
                'amount' => $amount,
                'originalAmount' => $amount + $discountAmount,
                'discountAmount' => $discountAmount,
            ];
        }

        $transaction = $this->recordTransaction($data['userId'], $data['planId'], $gatewayName, $clientReference, $amount, $currency, $periodMonths, 'SUBSCRIPTION', null, $promoCode?->id, $discountAmount ?: null);
        if ($promoCode) {
            $this->promoCodeService->createPendingRedemption($promoCode, $data['userId'], $transaction, $amount + $discountAmount, $discountAmount, (float) $amount, $currency);
        }

        return [
            'gateway' => $gatewayName,
            'amount' => $amount,
            'currency' => $currency,
            'clientReference' => $clientReference,
            'originalAmount' => $amount + $discountAmount,
            'discountAmount' => $discountAmount,
        ];
    }

    /**
     * Checkout d'une réinitialisation de quota (Standard/Premium, une fois
     * les tentatives "Limité" épuisées) : même infrastructure de gateway que
     * l'abonnement, montant = `reset_price` du plan actif plutôt que son prix.
     * La transaction est marquée `purpose: USAGE_RESET` pour que
     * verifyCheckout() remette le compteur à 0 au lieu d'activer un plan.
     *
     * @return array<string, mixed>
     */
    private function checkoutUsageReset(array $data, SubscriptionPlan $plan): array
    {
        $featureKey = $data['featureKey'] ?? null;
        if (! in_array($featureKey, UserFeatureUsageService::FEATURES, true)) {
            abort(400, 'Fonctionnalité inconnue.');
        }
        if ($plan->reset_price === null || (float) $plan->reset_price <= 0.0) {
            abort(400, 'Ce forfait ne propose pas de réinitialisation payante.');
        }

        $gatewayName = $data['gateway'] ?? $this->paymentGatewayManager->defaultGatewayName();
        $currency = $data['currency'] ?? 'XOF';
        $price = (float) $plan->reset_price;
        $amount = $currency === 'XOF' ? (int) round($price) : $price;
        $clientReference = $this->newPaymentReference();

        if ($this->paymentGatewayManager->supportsHostedCheckout($gatewayName)) {
            /** @var HostedCheckoutGatewayInterface $gateway */
            $gateway = $this->paymentGatewayManager->resolve($gatewayName);
            $session = $gateway->createCheckoutSession([
                'amount' => $amount,
                'currency' => $currency,
                'successUrl' => $data['successUrl'] ?? config('app.url'),
                'errorUrl' => $data['errorUrl'] ?? config('app.url'),
                'clientReference' => $clientReference,
                ...$this->customerDetails($data['userId'], $plan->id, $data['customerPhone'] ?? null),
            ]);

            $this->recordTransaction($data['userId'], $plan->id, $gatewayName, $session['sessionId'], $amount, $currency, 1, 'USAGE_RESET', $featureKey);

            return [
                'gateway' => $gatewayName,
                'checkoutUrl' => $session['checkoutUrl'],
                'sessionId' => $session['sessionId'],
            ];
        }

        $this->recordTransaction($data['userId'], $plan->id, $gatewayName, $clientReference, $amount, $currency, 1, 'USAGE_RESET', $featureKey);

        return [
            'gateway' => $gatewayName,
            'amount' => $amount,
            'currency' => $currency,
            'clientReference' => $clientReference,
        ];
    }

    /**
     * @return UserSubscriptionResource|array{reset: bool, featureKey: string, allowed: bool, used: int, remaining: int, limit: int}
     */
    public function verifyCheckout(string $transactionId, ?string $userId, ?string $planId, ?string $gateway = null): UserSubscriptionResource|array
    {
        $transactionRecord = Transaction::query()->where('gateway_transaction_id', $transactionId)->first();
        $gatewayName = $gateway ?? $transactionRecord?->gateway?->value ?? $this->paymentGatewayManager->defaultGatewayName();

        $transaction = $this->paymentGatewayManager->resolve($gatewayName)->verifyTransaction($transactionId);
        $this->markTransactionResult($transactionId, $transaction);

        if (($transaction['status'] ?? null) !== 'SUCCESS') {
            // PENDING = notification pas encore arrivée (le frontend re-tente) :
            // on ne libère le code promo que sur un échec tranché.
            if ($transactionRecord && $transactionRecord->promo_code_id && ($transaction['status'] ?? null) !== 'PENDING') {
                $this->promoCodeService->cancelRedemption($transactionRecord);
            }
            $status = $transaction['status'] ?? 'inconnu';
            abort(400, "Paiement non confirmé (statut: {$status})");
        }

        if ($transactionRecord && $transactionRecord->purpose === 'USAGE_RESET') {
            $user = User::find($transactionRecord->user_id);
            if (! $user) {
                abort(404, 'Utilisateur introuvable');
            }

            $this->userFeatureUsageService->reset($user, $transactionRecord->feature_key);

            return [
                'reset' => true,
                'featureKey' => $transactionRecord->feature_key,
                ...$this->userFeatureUsageService->status($user, $transactionRecord->feature_key),
            ];
        }

        // Transaction tracée au checkout (cas normal) : l'utilisateur, le plan
        // et la durée viennent d'ELLE, pas du query string de l'URL de retour
        // (que le prestataire de paiement peut altérer). fulfillTransaction()
        // est idempotent avec la notification serveur-à-serveur.
        if ($transactionRecord && $transactionRecord->user_id && $transactionRecord->plan_id) {
            $this->fulfillTransaction($transactionRecord);

            $sub = $this->userSubscriptionRepository->findActiveForUserAndPlan($transactionRecord->user_id, $transactionRecord->plan_id);
            if (! $sub) {
                abort(409, "Paiement confirmé mais l'abonnement n'est plus actif pour ce forfait.");
            }

            return new UserSubscriptionResource($sub);
        }

        // Idempotence : si déjà activé pour ce couple userId/planId, on
        // retourne l'abonnement existant plutôt que d'en créer un doublon.
        $existing = $this->userSubscriptionRepository->findActiveForUserAndPlan($userId, $planId);

        if ($existing) {
            if ($transactionRecord && $transactionRecord->promo_code_id) {
                $this->promoCodeService->confirmRedemption($transactionRecord, $existing->id);
            }

            return new UserSubscriptionResource($existing);
        }

        // Durée effectivement payée : tracée sur la Transaction au moment du
        // checkout (pas reprise du query string, non fiable) — 1 mois par
        // défaut si absente (anciennes transactions, ou plan sans période
        // choisissable).
        $periodMonths = (int) ($transactionRecord?->period_months ?? 1);

        $sub = $this->performSubscribe($userId, $planId, $periodMonths);

        if ($transactionRecord && $transactionRecord->promo_code_id) {
            $this->promoCodeService->confirmRedemption($transactionRecord, $sub->id);
        }

        return new UserSubscriptionResource($sub->load('plan'));
    }

    /**
     * Applique l'effet d'une Transaction confirmée (abonnement ou
     * réinitialisation de quota) à partir de ce qui a été tracé au checkout,
     * sans dépendre du retour du navigateur sur /auth/payment-success. Appelé
     * par la notification serveur-à-serveur (PaiementProWebhookController) :
     * si l'utilisateur ferme l'onglet ou que la notification arrive après les
     * re-tentatives du frontend, le forfait est quand même activé.
     *
     * Idempotent et sûr en concurrence : la ligne Transaction est verrouillée,
     * et un abonnement n'est créé que si AUCUN n'a déjà été ouvert pour cette
     * transaction. Peut donc être appelé par la notification (Paiement Pro en
     * envoie deux dans la même seconde), par verifyCheckout() et par
     * checkoutStatus() sans jamais produire de doublon.
     */
    public function fulfillTransaction(Transaction $transaction): void
    {
        if ($transaction->purpose === 'USAGE_RESET') {
            $user = User::find($transaction->user_id);
            if ($user) {
                $this->userFeatureUsageService->reset($user, $transaction->feature_key);
            }

            return;
        }

        DB::transaction(function () use ($transaction) {
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first() ?? $transaction;

            $sub = $this->subscriptionOpenedBy($locked);

            if (! $sub) {
                // Renouvellement du même forfait avant son échéance : la durée
                // achetée s'ajoute au temps restant au lieu de l'écraser.
                $current = $this->userSubscriptionRepository->findActiveForUserAndPlan($locked->user_id, $locked->plan_id);
                $from = $current?->end_date && $current->end_date->isFuture() ? $current->end_date : null;

                $sub = $this->performSubscribe($locked->user_id, $locked->plan_id, (int) ($locked->period_months ?? 1), $from);
            }

            if ($locked->promo_code_id) {
                $this->promoCodeService->confirmRedemption($locked, $sub->id);
            }
        });
    }

    /**
     * Abonnement déjà ouvert par cette transaction, s'il y en a un : même
     * utilisateur, même plan, démarré depuis la création de la transaction.
     */
    private function subscriptionOpenedBy(Transaction $transaction): ?UserSubscription
    {
        return UserSubscription::query()
            ->where('user_id', $transaction->user_id)
            ->where('plan_id', $transaction->plan_id)
            ->where('start_date', '>=', $transaction->created_at)
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * État d'un paiement, consultable SANS authentification à partir de sa
     * seule référence (page de retour /auth/payment-success). Après un
     * paiement mobile (Wave, Orange Money...), le retour s'ouvre souvent dans
     * un autre navigateur que celui où l'utilisateur était connecté : exiger
     * un JWT ici faisait afficher « Activation échouée » alors que le paiement
     * était encaissé et l'abonnement activé.
     *
     * Sert aussi de filet : un paiement confirmé dont l'abonnement n'aurait
     * pas été ouvert est activé ici.
     *
     * @return array{status: string, purpose: ?string, planName: ?string}
     */
    public function checkoutStatus(string $reference): array
    {
        $transaction = $this->findTransactionByReference($reference);

        if (! $transaction) {
            // Trace de ce que la page de retour nous a réellement transmis :
            // c'est elle qu'il faut relire si « paiement introuvable » revient.
            Log::warning('[paiement] statut demandé pour une référence inconnue', ['reference' => mb_substr($reference, 0, 300)]);

            return ['status' => 'UNKNOWN', 'purpose' => null, 'planName' => null];
        }

        if ($transaction->status === TransactionStatus::SUCCESS && $transaction->purpose !== 'USAGE_RESET') {
            try {
                $this->fulfillTransaction($transaction);
            } catch (Throwable $e) {
                Log::error('[paiement] activation échouée lors de la consultation du statut', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);

                // Encaissé mais pas activé : la page de retour continue de
                // patienter plutôt que d'annoncer un abonnement actif.
                return ['status' => 'PENDING', 'purpose' => $transaction->purpose, 'planName' => $transaction->plan?->name];
            }
        }

        return [
            'status' => match ($transaction->status) {
                TransactionStatus::SUCCESS => 'SUCCESS',
                TransactionStatus::FAILED, TransactionStatus::REFUNDED => 'FAILED',
                default => 'PENDING',
            },
            'purpose' => $transaction->purpose,
            'planName' => $transaction->plan?->name,
        ];
    }

    /**
     * Référence de paiement : courte, opaque et sans caractère à encoder.
     * L'ancien format « userId_planId_timestamp » (87 caractères) allongeait
     * l'URL de retour au-delà de ce que le prestataire restitue fidèlement.
     */
    private function newPaymentReference(): string
    {
        return self::REFERENCE_PREFIX.strtoupper((string) Str::ulid());
    }

    /**
     * Retrouve une Transaction à partir de la référence lue dans l'URL de
     * retour, que le prestataire de paiement peut avoir altérée : paramètres
     * accolés à la suite, ou URL tronquée. Correspondance exacte d'abord, puis
     * référence extraite du bruit, puis préfixe non ambigu.
     */
    private function findTransactionByReference(string $reference): ?Transaction
    {
        $reference = trim($reference);
        $candidates = [$reference];

        // Paramètres accolés par le prestataire : « REF?responsecode=0&... ».
        $candidates[] = (string) preg_split('/[?&#\s]/', $reference, 2)[0];

        // Référence noyée dans autre chose : on reconnaît nos deux formats.
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        if (preg_match('/'.self::REFERENCE_PREFIX.'[0-9A-Z]{26}/', $reference, $m)
            || preg_match("/{$uuid}_(?:{$uuid}|reset-[a-z_]+)_\d{13}/i", $reference, $m)) {
            $candidates[] = $m[0];
        }

        $candidates = array_values(array_unique(array_filter($candidates, fn (string $c) => $c !== '')));

        foreach ($candidates as $candidate) {
            $transaction = Transaction::query()->with('plan')->where('gateway_transaction_id', $candidate)->first();
            if ($transaction) {
                return $transaction;
            }
        }

        // URL de retour tronquée : la référence reçue n'est que le début de la
        // nôtre. Acceptée seulement si elle est assez longue pour ne pas se
        // deviner et qu'elle ne désigne qu'une seule transaction.
        $prefix = end($candidates) ?: '';
        if (strlen($prefix) < self::MIN_REFERENCE_PREFIX_LENGTH) {
            return null;
        }

        $matches = Transaction::query()
            ->with('plan')
            // substr plutôt que LIKE : nos anciennes références contiennent des
            // « _ », joker de LIKE dont l'échappement diffère entre MySQL et SQLite.
            ->whereRaw('substr(gateway_transaction_id, 1, ?) = ?', [strlen($prefix), $prefix])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @return array{total: int, active: int, expired: int, cancelled: int, revenue: float}
     */
    public function adminStats(): array
    {
        // Une requête agrégée au lieu de charger chaque abonnement et son forfait.
        $byStatus = UserSubscription::query()
            ->leftJoin('subscription_plans', 'subscription_plans.id', '=', 'user_subscriptions.plan_id')
            ->selectRaw('user_subscriptions.status as status, COUNT(*) as total, COALESCE(SUM(subscription_plans.price), 0) as revenue')
            ->groupBy('user_subscriptions.status')
            ->toBase()
            ->get()
            ->keyBy('status');

        $count = fn (SubscriptionStatus $status) => (int) ($byStatus[$status->value]->total ?? 0);

        return [
            'total' => (int) $byStatus->sum('total'),
            'active' => $count(SubscriptionStatus::ACTIVE),
            'expired' => $count(SubscriptionStatus::EXPIRED),
            'cancelled' => $count(SubscriptionStatus::CANCELLED),
            'revenue' => (float) ($byStatus[SubscriptionStatus::ACTIVE->value]->revenue ?? 0),
        ];
    }

    public function adminAll(int $page, int $limit)
    {
        $paginator = $this->userSubscriptionRepository->paginateAllWithPlanAndUser($page, $limit);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (UserSubscription $sub) => (new UserSubscriptionResource($sub))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /**
     * @return array<int, array{month: string, value: float}>
     */
    public function adminRevenueTrend(int $months = 6): array
    {
        return $this->monthlyTrend($months, fn ($start, $end) => (float) UserSubscription::query()
            ->whereBetween('start_date', [$start, $end])
            ->join('subscription_plans', 'subscription_plans.id', '=', 'user_subscriptions.plan_id')
            ->sum('subscription_plans.price'));
    }

    /**
     * @return array<int, array{month: string, value: int}>
     */
    public function adminSubscriptionsTrend(int $months = 6): array
    {
        return $this->monthlyTrend($months, fn ($start, $end) => UserSubscription::query()
            ->whereBetween('start_date', [$start, $end])
            ->count());
    }

    /**
     * @return array<int, array{month: string, value: int|float}>
     */
    private function monthlyTrend(int $months, \Closure $aggregate): array
    {
        $now = now();
        $monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
        $trend = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = $now->copy()->startOfMonth()->subMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();

            $trend[] = [
                'month' => $monthNames[$monthStart->month - 1],
                'value' => $aggregate($monthStart, $monthEnd),
            ];
        }

        return $trend;
    }

    /*
    |----------------------------------------------------------------------
    | Miroir de SubscriptionsService.subscribe() — partagé entre subscribe()
    | et verifyCheckout() pour éviter de dupliquer la logique.
    |----------------------------------------------------------------------
    */
    private function performSubscribe(string $userId, string $planId, int $periodMonths = 1, ?CarbonInterface $extendFrom = null): UserSubscription
    {
        $plan = $this->subscriptionPlanRepository->find($planId);
        if (! $plan) {
            abort(404, 'Plan non trouvé');
        }

        $this->userSubscriptionRepository->cancelActiveForUser($userId);

        // La durée vient de la période achetée (1/3/6/12 mois pour les
        // plans entreprise à période choisissable) plutôt que de
        // billing_period, qui ne distingue plus que MONTHLY/ANNUAL pour
        // l'affichage catalogue.
        $startDate = now();
        $endDate = ($extendFrom ?? $startDate)->copy()->addMonths(max(1, $periodMonths));

        return $this->userSubscriptionRepository->create([
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => SubscriptionStatus::ACTIVE,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'period_months' => max(1, $periodMonths),
            'auto_renew' => false,
        ]);
    }

    /**
     * Le modèle User n'a que `name` + `email` : on découpe `name` en prénom/nom
     * (sur le 1er espace) et on retombe sur le téléphone fourni au checkout ou
     * la valeur par défaut de config.
     *
     * @return array{customerEmail: ?string, customerFirstName: string, customerLastName: string, customerPhone: ?string, userId: string, planId: string}
     */
    private function customerDetails(string $userId, string $planId, ?string $phone): array
    {
        $user = User::find($userId);
        $parts = preg_split('/\s+/', trim((string) $user?->name), 2) ?: [];

        return [
            'customerEmail' => $user?->email,
            'customerFirstName' => $parts[0] ?? 'Client',
            'customerLastName' => $parts[1] ?? 'GORIYA',
            'customerPhone' => $phone,
            'userId' => $userId,
            'planId' => $planId,
        ];
    }

    private function recordTransaction(string $userId, string $planId, string $gateway, string $gatewayTransactionId, int|float $amount, string $currency, int $periodMonths = 1, string $purpose = 'SUBSCRIPTION', ?string $featureKey = null, ?string $promoCodeId = null, ?float $discountAmount = null): Transaction
    {
        return Transaction::create([
            'user_id' => $userId,
            'plan_id' => $planId,
            'gateway' => $gateway,
            'gateway_transaction_id' => $gatewayTransactionId,
            'amount' => $amount,
            'currency' => $currency,
            'period_months' => $periodMonths,
            'purpose' => $purpose,
            'feature_key' => $featureKey,
            'status' => TransactionStatus::PENDING,
            'promo_code_id' => $promoCodeId,
            'discount_amount' => $discountAmount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markTransactionResult(string $gatewayTransactionId, array $result): void
    {
        $status = match ($result['status'] ?? null) {
            'SUCCESS' => TransactionStatus::SUCCESS,
            'PENDING' => TransactionStatus::PENDING,
            default => TransactionStatus::FAILED,
        };

        // Rien à écrire tant que c'est PENDING — et surtout ne pas réécrire
        // PENDING par-dessus un SUCCESS/FAILED que la notification
        // serveur-à-serveur vient de poser entre notre lecture et cette écriture.
        if ($status === TransactionStatus::PENDING) {
            return;
        }

        // Instance update (pas Builder::update() en masse) pour que le cast
        // 'array' de raw_payload soit bien encodé en JSON avant écriture.
        Transaction::query()
            ->where('gateway_transaction_id', $gatewayTransactionId)
            ->first()
            ?->update(['status' => $status, 'raw_payload' => $result]);
    }
}
