<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\PaymentGatewayManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Périodicité entreprise (grille tarifaire sept. 2026) : Business et
 * Business+ ont un prix mensuel de base, multiplié par la durée choisie
 * (1/3/6/12 mois) au checkout — voir SubscriptionService::checkout() et
 * SubscriptionPlanSeeder.
 *
 * RefreshDatabase ne rejoue que les migrations (dont celle qui insère
 * "Offre gratuite" en dur) — pas les seeders classiques : on lance donc
 * explicitement SubscriptionPlanSeeder pour disposer de Business/Business+.
 */
class EnterprisePlanPeriodicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);

        // Le montant/la durée se décident avant que le gateway ne soit
        // sollicité — on n'a pas besoin d'un vrai gateway (clés Kkiapay,
        // etc.) pour tester ce calcul, seulement du chemin "non hébergé"
        // (SubscriptionService::checkout() enregistre alors amount/currency
        // directement sans appel externe).
        $this->mock(PaymentGatewayManager::class, function ($mock) {
            $mock->shouldReceive('supportsHostedCheckout')->andReturn(false);
            $mock->shouldReceive('resolve')->andReturnSelf();
            $mock->shouldReceive('verifyTransaction')->andReturn(['status' => 'SUCCESS']);
        });
    }

    private function enterpriseUser(): User
    {
        return User::create([
            'name' => 'Goriya Test SARL',
            'email' => 'contact@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_business_plan_is_seeded_with_selectable_periods(): void
    {
        $plan = SubscriptionPlan::where('name', 'Business')->firstOrFail();

        $this->assertSame(35500.0, (float) $plan->price);
        $this->assertSame([1, 3, 6, 12], $plan->available_periods);
        $this->assertSame('FAIBLE', $plan->notification_level);
    }

    public function test_business_plus_has_a_monthly_base_price_instead_of_annual_only(): void
    {
        $plan = SubscriptionPlan::where('name', 'Business+')->firstOrFail();

        $this->assertSame(45500.0, (float) $plan->price);
        $this->assertSame('MONTHLY', $plan->billing_period->value);
        $this->assertSame([1, 3, 6, 12], $plan->available_periods);
        $this->assertSame('ELEVE', $plan->notification_level);
    }

    public function test_checkout_multiplies_the_monthly_price_by_the_chosen_period(): void
    {
        $user = $this->enterpriseUser();
        $plan = SubscriptionPlan::where('name', 'Business+')->firstOrFail();

        $response = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'periodMonths' => 6,
        ])->assertOk();

        $response->assertJsonPath('amount', 45500 * 6);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period_months' => 6,
            'amount' => 45500 * 6,
        ]);
    }

    public function test_checkout_falls_back_to_one_month_for_a_period_the_plan_does_not_offer(): void
    {
        $user = $this->enterpriseUser();
        $plan = SubscriptionPlan::where('name', 'Business')->firstOrFail();

        $response = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'periodMonths' => 9,
        ])->assertOk();

        $response->assertJsonPath('amount', 35500);
    }

    public function test_standard_user_plan_ignores_period_months_and_stays_at_one_month(): void
    {
        $user = User::create([
            'name' => 'Candidat Test',
            'email' => 'candidat@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $plan = SubscriptionPlan::where('name', 'Standard')->firstOrFail();

        $response = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
            'periodMonths' => 12,
        ])->assertOk();

        $response->assertJsonPath('amount', 1999);
        $this->assertSame(['cv_creation' => 5, 'document_generation' => 5, 'cv_analysis' => 5], $plan->feature_limits);
        $this->assertSame(500.0, (float) $plan->reset_price);
    }

    public function test_verify_checkout_activates_a_subscription_lasting_the_paid_period(): void
    {
        $user = $this->enterpriseUser();
        $plan = SubscriptionPlan::where('name', 'Business+')->firstOrFail();

        Transaction::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'gateway' => 'kkiapay',
            'gateway_transaction_id' => 'txn-period-3',
            'amount' => 45500 * 3,
            'currency' => 'XOF',
            'period_months' => 3,
            'status' => 'SUCCESS',
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/subscriptions/checkout/verify/txn-period-3?userId={$user->id}&planId={$plan->id}&gateway=kkiapay")
            // 201 : l'abonnement vient d'être créé (JsonResource sur un
            // modèle wasRecentlyCreated), même convention que /subscribe.
            ->assertSuccessful();

        $this->assertDatabaseHas('user_subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period_months' => 3,
        ]);

        $created = UserSubscription::where('user_id', $user->id)->firstOrFail();
        $this->assertEqualsWithDelta(
            $created->start_date->copy()->addMonths(3)->timestamp,
            $created->end_date->timestamp,
            5
        );
    }
}
