<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Resources\UserSubscriptionResource;
use App\Models\UserSubscription;
use App\Repositories\Contracts\SubscriptionPlanRepositoryInterface;
use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;
use App\Support\ApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Mirroir de backend/src/subscriptions/subscriptions.service.ts. Extrait de
 * SubscriptionsController pour cohérence avec le reste du port.
 */
class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionPlanRepositoryInterface $subscriptionPlanRepository,
        private readonly UserSubscriptionRepositoryInterface $userSubscriptionRepository,
        private readonly PaymentGatewayInterface $paymentGateway,
    ) {}

    public function plans(?string $userType): AnonymousResourceCollection
    {
        return SubscriptionPlanResource::collection($this->subscriptionPlanRepository->findActive($userType));
    }

    public function subscribe(string $userId, string $planId): UserSubscriptionResource
    {
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
     * @return array{hasSubscription: bool, planName: ?string, status: ?string}
     */
    public function check(string $userId): array
    {
        $sub = $this->userSubscriptionRepository->findActiveForUser($userId);

        return [
            'hasSubscription' => (bool) $sub,
            'planName' => $sub?->plan?->name,
            'status' => $sub?->status?->value,
        ];
    }

    /**
     * Kkiapay initie le paiement côté client (widget JS + clé publique) — le
     * backend se contente ici de valider le plan et de fournir le montant et
     * la référence que le frontend transmettra au widget.
     *
     * @return array{amount: int, currency: string, clientReference: string}
     */
    public function checkout(array $data): array
    {
        $plan = $this->subscriptionPlanRepository->find($data['planId']);
        if (! $plan) {
            abort(404, 'Plan non trouvé');
        }
        if ((float) $plan->price === 0.0) {
            abort(400, 'Ce plan est gratuit, utilisez /subscribe directement');
        }

        // XOF n'a pas de sous-unité décimale — le montant doit être un entier.
        $amount = (int) round((float) $plan->price);
        $clientReference = "{$data['userId']}_{$data['planId']}_".(int) round(microtime(true) * 1000);

        return [
            'amount' => $amount,
            'currency' => 'XOF',
            'clientReference' => $clientReference,
        ];
    }

    public function verifyCheckout(string $transactionId, ?string $userId, ?string $planId): UserSubscriptionResource
    {
        $transaction = $this->paymentGateway->verifyTransaction($transactionId);

        if (($transaction['status'] ?? null) !== 'SUCCESS') {
            $status = $transaction['status'] ?? 'inconnu';
            abort(400, "Paiement non confirmé (statut: {$status})");
        }

        // Idempotence : si déjà activé pour ce couple userId/planId, on
        // retourne l'abonnement existant plutôt que d'en créer un doublon.
        $existing = $this->userSubscriptionRepository->findActiveForUserAndPlan($userId, $planId);

        if ($existing) {
            return new UserSubscriptionResource($existing);
        }

        $sub = $this->performSubscribe($userId, $planId);

        return new UserSubscriptionResource($sub->load('plan'));
    }

    /**
     * @return array{total: int, active: int, expired: int, cancelled: int, revenue: float}
     */
    public function adminStats(): array
    {
        $all = $this->userSubscriptionRepository->findAllWithPlan();
        $active = $all->filter(fn (UserSubscription $s) => $s->status === SubscriptionStatus::ACTIVE);
        $expired = $all->filter(fn (UserSubscription $s) => $s->status === SubscriptionStatus::EXPIRED);
        $cancelled = $all->filter(fn (UserSubscription $s) => $s->status === SubscriptionStatus::CANCELLED);
        $revenue = $active->sum(fn (UserSubscription $s) => (float) ($s->plan->price ?? 0));

        return [
            'total' => $all->count(),
            'active' => $active->count(),
            'expired' => $expired->count(),
            'cancelled' => $cancelled->count(),
            'revenue' => $revenue,
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
    private function performSubscribe(string $userId, string $planId): UserSubscription
    {
        $plan = $this->subscriptionPlanRepository->find($planId);
        if (! $plan) {
            abort(404, 'Plan non trouvé');
        }

        $this->userSubscriptionRepository->cancelActiveForUser($userId);

        $startDate = now();
        $endDate = $plan->billing_period === BillingPeriod::ANNUAL
            ? $startDate->copy()->addYear()
            : $startDate->copy()->addMonth();

        return $this->userSubscriptionRepository->create([
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => SubscriptionStatus::ACTIVE,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'auto_renew' => false,
        ]);
    }
}
