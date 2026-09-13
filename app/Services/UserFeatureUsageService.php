<?php

namespace App\Services;

use App\Models\FeatureUsage;
use App\Models\User;
use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;

/**
 * Quota des fonctionnalités "Limité" de la grille tarifaire (sept. 2026) :
 * création de CV, génération de documents, analyse de CV. Mirroir
 * authentifié de AnonymousUsageService (deviceId + featureKey) — même forme
 * de réponse ({allowed, used, remaining, limit}), scopé ici par abonnement
 * actif plutôt que par appareil.
 *
 * Le compteur vit sur l'abonnement actif (`user_subscription_id`), pas sur
 * une fenêtre calendaire : un réabonnement ou un changement de forfait crée
 * une nouvelle UserSubscription (voir SubscriptionService::performSubscribe())
 * donc repart naturellement de 0, sans job de reset à planifier.
 */
class UserFeatureUsageService
{
    /**
     * Clés reconnues — alignées sur les lignes "Limité" du PDF tarifaire.
     * Une clé hors de cette liste n'est simplement jamais incluse dans
     * `feature_limits` d'aucun plan, donc toujours refusée.
     *
     * @var list<string>
     */
    public const FEATURES = ['cv_creation', 'document_generation', 'cv_analysis'];

    public function __construct(
        private readonly UserSubscriptionRepositoryInterface $userSubscriptionRepository,
    ) {}

    /**
     * Lecture seule — n'incrémente rien, sert à afficher "3/5 restantes"
     * avant que l'utilisateur ne déclenche l'action.
     *
     * @return array{allowed: bool, used: int, remaining: int, limit: int}
     */
    public function status(User $user, string $featureKey): array
    {
        [$subscription, $limit] = $this->resolve($user, $featureKey);

        if ($subscription === null || $limit === null) {
            return ['allowed' => false, 'used' => 0, 'remaining' => 0, 'limit' => 0];
        }

        $used = (int) (FeatureUsage::where('user_subscription_id', $subscription->id)
            ->where('feature_key', $featureKey)
            ->value('count') ?? 0);

        return ['allowed' => $used < $limit, 'used' => $used, 'remaining' => max(0, $limit - $used), 'limit' => $limit];
    }

    /**
     * Vérifie et consomme atomiquement une utilisation. À appeler juste
     * avant (ou juste après succès de) la fonctionnalité, comme
     * AnonymousUsageService::consume().
     *
     * @return array{allowed: bool, used: int, remaining: int, limit: int}
     */
    public function consume(User $user, string $featureKey): array
    {
        [$subscription, $limit] = $this->resolve($user, $featureKey);

        if ($subscription === null || $limit === null) {
            return ['allowed' => false, 'used' => 0, 'remaining' => 0, 'limit' => 0];
        }

        $usage = FeatureUsage::firstOrNew([
            'user_subscription_id' => $subscription->id,
            'feature_key' => $featureKey,
        ]);
        if (! $usage->exists) {
            $usage->user_id = $user->id;
            $usage->count = 0;
        }

        if ($usage->count >= $limit) {
            return ['allowed' => false, 'used' => $usage->count, 'remaining' => 0, 'limit' => $limit];
        }

        $usage->count += 1;
        $usage->save();

        return ['allowed' => true, 'used' => $usage->count, 'remaining' => $limit - $usage->count, 'limit' => $limit];
    }

    /**
     * Remet le compteur à 0 après un paiement de réinitialisation confirmé
     * (voir SubscriptionService::verifyCheckout(), purpose USAGE_RESET).
     * Sans abonnement actif il n'y a rien à réinitialiser — abort() plutôt
     * que de rester silencieux : le paiement a été pris, l'appelant doit le
     * savoir si l'état a changé entre-temps (ex. abonnement expiré).
     */
    public function reset(User $user, string $featureKey): void
    {
        $subscription = $this->userSubscriptionRepository->findActiveForUser($user->id);
        if (! $subscription) {
            abort(409, "Aucun abonnement actif : impossible de réinitialiser les tentatives.");
        }

        FeatureUsage::updateOrCreate(
            ['user_subscription_id' => $subscription->id, 'feature_key' => $featureKey],
            ['user_id' => $user->id, 'count' => 0]
        );
    }

    /**
     * @return array{0: ?\App\Models\UserSubscription, 1: ?int}
     */
    private function resolve(User $user, string $featureKey): array
    {
        $subscription = $this->userSubscriptionRepository->findActiveForUser($user->id);
        $limit = $subscription?->plan?->featureLimit($featureKey);

        return [$subscription, $limit];
    }
}
