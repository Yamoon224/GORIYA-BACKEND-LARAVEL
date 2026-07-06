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
     * @return array{waveUrl: ?string, sessionId: ?string, clientReference: string, expiresAt: mixed}
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

        // XOF n'a pas de sous-unité décimale — le montant doit être une
        // chaîne d'entier.
        $amount = (string) (int) round((float) $plan->price);
        $clientReference = "{$data['userId']}_{$data['planId']}_".(int) round(microtime(true) * 1000);

        $fullSuccess = "{$data['successUrl']}?ref={$clientReference}&userId={$data['userId']}&planId={$data['planId']}";
        $fullError = "{$data['errorUrl']}?planId={$data['planId']}";

        $session = $this->paymentGateway->createCheckoutSession([
            'amount' => $amount,
            'currency' => 'XOF',
            'successUrl' => $fullSuccess,
            'errorUrl' => $fullError,
            'clientReference' => $clientReference,
        ]);

        return [
            'waveUrl' => $session['wave_launch_url'] ?? null,
            'sessionId' => $session['id'] ?? null,
            'clientReference' => $clientReference,
            'expiresAt' => $session['when_expires'] ?? null,
        ];
    }

    public function verifyCheckout(string $sessionId, ?string $userId, ?string $planId): UserSubscriptionResource
    {
        $session = $this->paymentGateway->getCheckoutSession($sessionId);

        if (($session['payment_status'] ?? null) !== 'succeeded') {
            $status = $session['payment_status'] ?? 'inconnu';
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
