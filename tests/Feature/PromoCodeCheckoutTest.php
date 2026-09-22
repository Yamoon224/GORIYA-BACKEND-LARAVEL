<?php

namespace Tests\Feature;

use App\Models\Influencer;
use App\Models\PromoCampaign;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Code promo au checkout : réduit le montant facturé, crée une redemption
 * PENDING, puis CONFIRMED (avec calcul de commission) une fois le paiement
 * vérifié — voir SubscriptionService::checkout()/verifyCheckout() et
 * PromoCodeService.
 */
class PromoCodeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Chemin "non hébergé" : pas besoin d'un vrai gateway pour tester le
        // calcul de réduction, cf. EnterprisePlanPeriodicityTest.
        $this->mock(PaymentGatewayManager::class, function ($mock) {
            $mock->shouldReceive('supportsHostedCheckout')->andReturn(false);
            $mock->shouldReceive('resolve')->andReturnSelf();
            $mock->shouldReceive('verifyTransaction')->andReturn(['status' => 'SUCCESS']);
        });
    }

    private function user(string $email = 'candidat@goriya-test.ci'): User
    {
        return User::create([
            'name' => 'Candidat Test',
            'email' => $email,
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
    }

    private function plan(float $price = 10000): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Premium',
            'price' => $price,
            'billing_period' => 'MONTHLY',
            'user_type' => 'USER',
            'is_active' => true,
            'features' => [],
        ]);
    }

    private function campaign(string $discountType = 'PERCENTAGE', float $discountValue = 20): PromoCampaign
    {
        return PromoCampaign::create([
            'name' => 'Rentrée 2026',
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'applicable_user_types' => ['USER'],
            'status' => 'ACTIVE',
        ]);
    }

    public function test_checkout_applies_a_percentage_discount_and_creates_a_pending_redemption(): void
    {
        $user = $this->user();
        $plan = $this->plan(10000);
        $campaign = $this->campaign('PERCENTAGE', 20);
        $code = PromoCode::create([
            'campaign_id' => $campaign->id,
            'code' => 'promo20',
        ]);

        $response = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'promoCode' => 'promo20',
        ])->assertOk();

        $response->assertJsonPath('amount', 8000);
        $response->assertJsonPath('discountAmount', 2000);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount' => 8000,
            'promo_code_id' => $code->id,
        ]);

        $redemption = PromoCodeRedemption::where('promo_code_id', $code->id)->firstOrFail();
        $this->assertSame('PENDING', $redemption->status->value);
        $this->assertSame(0, $code->fresh()->used_count);
    }

    public function test_verify_checkout_confirms_the_redemption_and_computes_commission(): void
    {
        $user = $this->user();
        $plan = $this->plan(10000);
        $influencer = Influencer::create([
            'name' => 'Aïcha Promo',
            'default_commission_rate' => 10,
        ]);
        $campaign = $this->campaign('FIXED', 1500);
        $code = PromoCode::create([
            'campaign_id' => $campaign->id,
            'influencer_id' => $influencer->id,
            'code' => 'AICHA1500',
        ]);

        $checkout = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'promoCode' => 'AICHA1500',
        ])->assertOk();

        $transactionId = Transaction::where('user_id', $user->id)->firstOrFail()->gateway_transaction_id;
        $checkout->assertJsonPath('amount', 8500);

        $this->actingAs($user, 'api')
            ->getJson("/subscriptions/checkout/verify/{$transactionId}?userId={$user->id}&planId={$plan->id}&gateway=kkiapay")
            ->assertSuccessful();

        $redemption = PromoCodeRedemption::where('promo_code_id', $code->id)->firstOrFail();
        $this->assertSame('CONFIRMED', $redemption->status->value);
        $this->assertSame(850.0, (float) $redemption->commission_amount);
        $this->assertSame(1, $code->fresh()->used_count);
        $this->assertSame(850.0, $influencer->fresh()->unpaidBalance());
    }

    public function test_checkout_rejects_an_expired_or_unknown_promo_code(): void
    {
        $user = $this->user();
        $plan = $this->plan(10000);

        $response = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'promoCode' => 'DOESNOTEXIST',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_checkout_rejects_a_code_that_already_reached_its_usage_cap(): void
    {
        $user = $this->user();
        $plan = $this->plan(10000);
        $campaign = $this->campaign();
        PromoCode::create([
            'campaign_id' => $campaign->id,
            'code' => 'LIMITED1',
            'max_uses' => 1,
            'used_count' => 1,
        ]);

        $response = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'promoCode' => 'LIMITED1',
        ]);

        $response->assertStatus(422);
    }

    public function test_checkout_rejects_a_code_restricted_to_a_different_specific_plan(): void
    {
        $user = $this->user();
        $eligiblePlan = $this->plan(10000);
        $otherPlan = SubscriptionPlan::create([
            'name' => 'Standard',
            'price' => 1999,
            'billing_period' => 'MONTHLY',
            'user_type' => 'USER',
            'is_active' => true,
            'features' => [],
        ]);

        $campaign = PromoCampaign::create([
            'name' => 'Premium uniquement',
            'discount_type' => 'PERCENTAGE',
            'discount_value' => 20,
            'applicable_user_types' => ['USER'],
            // Restreint au seul plan Premium - le plan Standard ne doit pas
            // pouvoir utiliser ce code.
            'applicable_plan_ids' => [$eligiblePlan->id],
            'status' => 'ACTIVE',
        ]);
        PromoCode::create(['campaign_id' => $campaign->id, 'code' => 'PREMIUMONLY', 'max_uses' => 10]);

        $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $otherPlan->id,
            'gateway' => 'kkiapay',
            'promoCode' => 'PREMIUMONLY',
        ])->assertStatus(422);

        $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $eligiblePlan->id,
            'gateway' => 'kkiapay',
            'promoCode' => 'PREMIUMONLY',
        ])->assertOk()->assertJsonPath('amount', 8000);
    }

    public function test_usage_reset_checkout_ignores_the_promo_code_field(): void
    {
        $user = $this->user();
        $plan = SubscriptionPlan::create([
            'name' => 'Standard',
            'price' => 1999,
            'billing_period' => 'MONTHLY',
            'user_type' => 'USER',
            'is_active' => true,
            'features' => [],
            'reset_price' => 500,
        ]);
        $campaign = $this->campaign();
        PromoCode::create(['campaign_id' => $campaign->id, 'code' => 'IGNOREME']);

        $response = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'purpose' => 'USAGE_RESET',
            'featureKey' => 'cv_creation',
            'promoCode' => 'IGNOREME',
        ])->assertOk();

        $response->assertJsonPath('amount', 500);
        $this->assertDatabaseCount('promo_code_redemptions', 0);
    }
}
