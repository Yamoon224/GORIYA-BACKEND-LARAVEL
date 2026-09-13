<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentGatewayManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quota des fonctionnalités "Limité" (création de CV, génération de
 * documents, analyse de CV) et réinitialisation payante (500 XOF) — voir
 * UserFeatureUsageService et SubscriptionService::checkoutUsageReset().
 */
class FeatureUsageQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);

        $this->mock(PaymentGatewayManager::class, function ($mock) {
            $mock->shouldReceive('supportsHostedCheckout')->andReturn(false);
            $mock->shouldReceive('resolve')->andReturnSelf();
            $mock->shouldReceive('verifyTransaction')->andReturn(['status' => 'SUCCESS']);
        });
    }

    private function userWithPlan(string $planName): User
    {
        $user = User::create([
            'name' => 'Candidat Test',
            'email' => 'candidat-'.uniqid().'@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        $plan = SubscriptionPlan::where('name', $planName)->firstOrFail();

        if ($plan->isFree()) {
            $this->actingAs($user, 'api')->postJson('/subscriptions/subscribe', [
                'userId' => $user->id,
                'planId' => $plan->id,
            ])->assertSuccessful();

            return $user;
        }

        // Plan payant : /subscribe le refuse (403) — passer par le vrai
        // parcours checkout + verify, comme un utilisateur réel.
        $checkout = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
        ])->assertOk();

        $txnId = $checkout->json('clientReference');
        $this->actingAs($user, 'api')
            ->getJson("/subscriptions/checkout/verify/{$txnId}?userId={$user->id}&planId={$plan->id}&gateway=kkiapay")
            ->assertSuccessful();

        return $user;
    }

    public function test_a_feature_not_included_in_the_plan_is_never_allowed(): void
    {
        // Grouilleur n'a que cv_analysis dans feature_limits : cv_creation
        // n'y figure pas du tout, donc toujours refusé (pas "illimité").
        $user = $this->userWithPlan('Grouilleur');

        $this->actingAs($user, 'api')
            ->getJson('/me/feature-usage/cv_creation')
            ->assertOk()
            ->assertJson(['allowed' => false, 'used' => 0, 'remaining' => 0, 'limit' => 0]);
    }

    public function test_grouilleur_is_limited_to_two_cv_analyses(): void
    {
        $user = $this->userWithPlan('Grouilleur');

        foreach ([1, 2] as $expectedUsed) {
            $this->actingAs($user, 'api')
                ->postJson('/me/feature-usage/cv_analysis/consume')
                ->assertOk()
                ->assertJson(['allowed' => true, 'used' => $expectedUsed, 'limit' => 2]);
        }

        // Une 3e tentative est refusée sans avoir incrémenté davantage.
        $this->actingAs($user, 'api')
            ->postJson('/me/feature-usage/cv_analysis/consume')
            ->assertOk()
            ->assertJson(['allowed' => false, 'used' => 2, 'remaining' => 0, 'limit' => 2]);
    }

    public function test_standard_and_premium_have_their_own_limits_per_feature(): void
    {
        $standard = $this->userWithPlan('Standard');
        $premium = $this->userWithPlan('Premium');

        $this->actingAs($standard, 'api')
            ->getJson('/me/feature-usage/document_generation')
            ->assertOk()
            ->assertJson(['allowed' => true, 'limit' => 5]);

        $this->actingAs($premium, 'api')
            ->getJson('/me/feature-usage/document_generation')
            ->assertOk()
            ->assertJson(['allowed' => true, 'limit' => 20]);
    }

    public function test_a_new_subscription_resets_the_counter_naturally(): void
    {
        $user = $this->userWithPlan('Grouilleur');

        $this->actingAs($user, 'api')->postJson('/me/feature-usage/cv_analysis/consume')->assertOk();
        $this->actingAs($user, 'api')->postJson('/me/feature-usage/cv_analysis/consume')->assertOk();
        $this->actingAs($user, 'api')
            ->postJson('/me/feature-usage/cv_analysis/consume')
            ->assertJson(['allowed' => false]);

        // Upgrade vers Standard : nouvelle UserSubscription -> quota reparti de 0.
        $standardPlan = SubscriptionPlan::where('name', 'Standard')->firstOrFail();
        $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $standardPlan->id,
            'gateway' => 'kkiapay',
        ])->assertOk();

        $txnId = Transaction::where('user_id', $user->id)->latest()->first()->gateway_transaction_id;
        $this->actingAs($user, 'api')
            ->getJson("/subscriptions/checkout/verify/{$txnId}?userId={$user->id}&planId={$standardPlan->id}&gateway=kkiapay")
            ->assertSuccessful();

        $this->actingAs($user, 'api')
            ->getJson('/me/feature-usage/cv_analysis')
            ->assertOk()
            ->assertJson(['allowed' => true, 'used' => 0, 'remaining' => 5, 'limit' => 5]);
    }

    public function test_paying_the_reset_price_brings_the_counter_back_to_zero(): void
    {
        $user = $this->userWithPlan('Standard');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user, 'api')->postJson('/me/feature-usage/cv_analysis/consume')->assertOk();
        }
        $this->actingAs($user, 'api')
            ->postJson('/me/feature-usage/cv_analysis/consume')
            ->assertJson(['allowed' => false, 'used' => 5, 'limit' => 5]);

        $plan = SubscriptionPlan::where('name', 'Standard')->firstOrFail();
        $checkout = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'purpose' => 'USAGE_RESET',
            'featureKey' => 'cv_analysis',
        ])->assertOk();

        // Le montant facturé est le prix de réinitialisation, pas le prix d'abonnement.
        $checkout->assertJsonPath('amount', 500);

        $txnId = $checkout->json('clientReference');
        $this->assertDatabaseHas('transactions', [
            'gateway_transaction_id' => $txnId,
            'purpose' => 'USAGE_RESET',
            'feature_key' => 'cv_analysis',
            'amount' => 500,
        ]);

        $verify = $this->actingAs($user, 'api')
            ->getJson("/subscriptions/checkout/verify/{$txnId}?userId={$user->id}&planId={$plan->id}&gateway=kkiapay")
            ->assertOk();

        $verify->assertJson(['reset' => true, 'featureKey' => 'cv_analysis', 'allowed' => true, 'used' => 0, 'remaining' => 5, 'limit' => 5]);

        $this->actingAs($user, 'api')
            ->getJson('/me/feature-usage/cv_analysis')
            ->assertJson(['used' => 0, 'remaining' => 5]);
    }

    public function test_a_plan_without_a_reset_price_refuses_the_reset_checkout(): void
    {
        $user = $this->userWithPlan('Grouilleur');
        $plan = SubscriptionPlan::where('name', 'Grouilleur')->firstOrFail();

        $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'purpose' => 'USAGE_RESET',
            'featureKey' => 'cv_analysis',
        ])->assertStatus(400);
    }
}
