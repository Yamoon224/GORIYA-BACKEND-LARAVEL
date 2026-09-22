<?php

namespace Tests\Feature;

use App\Enums\PromoRedemptionStatus;
use App\Models\Influencer;
use App\Models\PromoCampaign;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInfluencerPayoutsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Goriya',
            'email' => 'admin@goriya.net',
            'password' => 'motdepasse-solide',
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Redemption CONFIRMED avec une commission due, prête à être versée.
     */
    private function confirmedRedemption(Influencer $influencer, float $commissionAmount = 850): PromoCodeRedemption
    {
        $buyer = User::create([
            'name' => 'Candidat Test',
            'email' => 'candidat@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Premium',
            'price' => 10000,
            'billing_period' => 'MONTHLY',
            'user_type' => 'USER',
            'is_active' => true,
            'features' => [],
        ]);
        $campaign = PromoCampaign::create([
            'name' => 'Campagne',
            'discount_type' => 'FIXED',
            'discount_value' => 1500,
            'applicable_user_types' => ['USER'],
            'status' => 'ACTIVE',
        ]);
        $code = PromoCode::create([
            'campaign_id' => $campaign->id,
            'influencer_id' => $influencer->id,
            'code' => 'PAYOUTTEST',
        ]);
        $transaction = Transaction::create([
            'user_id' => $buyer->id,
            'plan_id' => $plan->id,
            'gateway' => 'kkiapay',
            'gateway_transaction_id' => 'txn-payout-test',
            'amount' => 8500,
            'currency' => 'XOF',
            'status' => 'SUCCESS',
        ]);

        return PromoCodeRedemption::create([
            'promo_code_id' => $code->id,
            'influencer_id' => $influencer->id,
            'user_id' => $buyer->id,
            'transaction_id' => $transaction->id,
            'plan_id' => $plan->id,
            'original_amount' => 10000,
            'discount_amount' => 1500,
            'final_amount' => 8500,
            'commission_rate' => 10,
            'commission_amount' => $commissionAmount,
            'status' => PromoRedemptionStatus::CONFIRMED->value,
        ]);
    }

    public function test_admin_can_create_a_payout_from_confirmed_redemptions(): void
    {
        $admin = $this->admin();
        $influencer = Influencer::create(['name' => 'Aïcha Promo']);
        $redemption = $this->confirmedRedemption($influencer, 850);

        $this->assertSame(850.0, $influencer->fresh()->unpaidBalance());

        $this->actingAs($admin, 'api')->postJson('/admin/influencer-payouts', [
            'influencerId' => $influencer->id,
            'redemptionIds' => [$redemption->id],
            'paymentMethod' => 'Mobile Money',
        ])->assertCreated()
            ->assertJsonPath('data.amount', 850)
            ->assertJsonPath('data.status', 'PENDING');

        $this->assertSame(0.0, $influencer->fresh()->unpaidBalance());
        $this->assertSame(850.0, (float) $redemption->fresh()->payout->amount);
    }

    public function test_marking_a_payout_paid_sets_paid_at(): void
    {
        $admin = $this->admin();
        $influencer = Influencer::create(['name' => 'Aïcha Promo']);
        $redemption = $this->confirmedRedemption($influencer, 850);

        $payoutId = $this->actingAs($admin, 'api')->postJson('/admin/influencer-payouts', [
            'influencerId' => $influencer->id,
            'redemptionIds' => [$redemption->id],
        ])->json('data.id');

        $response = $this->actingAs($admin, 'api')
            ->patchJson("/admin/influencer-payouts/{$payoutId}", ['status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');

        $this->assertNotNull($response->json('data.paidAt'));
    }

    public function test_non_admin_cannot_manage_payouts(): void
    {
        $user = User::create([
            'name' => 'Candidat Test 2',
            'email' => 'candidat2@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/admin/influencer-payouts/paginate')
            ->assertStatus(403);
    }
}
