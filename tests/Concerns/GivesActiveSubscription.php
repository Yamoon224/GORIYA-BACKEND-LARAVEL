<?php

namespace Tests\Concerns;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Database\Seeders\SubscriptionPlanSeeder;

/**
 * Fixture d'abonnement pour les tests qui portent sur une fonctionnalité
 * gatée par plan (`plan.feature`/`UserFeatureUsageService`) sans tester le
 * paiement lui-même — évite de rejouer tout le parcours checkout/verify
 * juste pour obtenir un abonnement actif. `SubscriptionPlanSeeder` doit être
 * lancé au préalable (RefreshDatabase ne rejoue que les migrations, pas les
 * seeders classiques).
 */
trait GivesActiveSubscription
{
    protected function seedSubscriptionPlans(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
    }

    protected function giveActiveSubscription(User $user, string $planName): UserSubscription
    {
        $plan = SubscriptionPlan::where('name', $planName)->firstOrFail();

        return UserSubscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'ACTIVE',
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'period_months' => 1,
            'auto_renew' => false,
        ]);
    }
}
