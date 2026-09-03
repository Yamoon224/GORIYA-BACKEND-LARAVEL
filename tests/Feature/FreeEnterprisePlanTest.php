<?php

namespace Tests\Feature;

use App\Enums\BillingPeriod;
use App\Enums\SubscriptionUserType;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Offre gratuite entreprise.
 *
 * Elle s'active sans paiement via POST /subscriptions/subscribe, mais cet
 * endpoint ne doit pas devenir une porte dérobée vers les forfaits payants :
 * il est désormais restreint aux plans à 0 et au compte de l'appelant.
 * GET /subscriptions/check expose le palier (`tier`) pour que le frontend
 * garde les fonctionnalités premium fermées à un abonnement gratuit.
 */
class FreeEnterprisePlanTest extends TestCase
{
    use RefreshDatabase;

    private function enterpriseUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Goriya Test SARL',
            'email' => 'contact@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
        ], $attributes));
    }

    private function freePlan(): SubscriptionPlan
    {
        return SubscriptionPlan::where('name', 'Offre gratuite')->firstOrFail();
    }

    private function paidPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Business',
            'price' => 35500,
            'billing_period' => BillingPeriod::MONTHLY,
            'user_type' => SubscriptionUserType::ENTERPRISE,
            'features' => ['Support prioritaire'],
            'is_active' => true,
        ]);
    }

    public function test_the_free_enterprise_plan_is_seeded_by_the_migration(): void
    {
        $plan = $this->freePlan();

        $this->assertSame(0.0, (float) $plan->price);
        $this->assertSame(SubscriptionUserType::ENTERPRISE, $plan->user_type);
        $this->assertTrue($plan->is_active);
        $this->assertTrue($plan->isFree());
        $this->assertSame('FREE', $plan->tier());
    }

    public function test_it_is_listed_among_the_enterprise_plans(): void
    {
        $this->getJson('/subscriptions/plans?userType=ENTREPRISE')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Offre gratuite', 'price' => 0.0]);
    }

    public function test_an_enterprise_can_activate_it_without_paying(): void
    {
        $user = $this->enterpriseUser();
        $plan = $this->freePlan();

        $this->actingAs($user, 'api')
            // 201 : la ressource d'abonnement est créée (comportement existant).
            ->postJson('/subscriptions/subscribe', ['userId' => $user->id, 'planId' => $plan->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('user_subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_check_reports_the_free_tier(): void
    {
        $user = $this->enterpriseUser();
        $plan = $this->freePlan();

        $this->actingAs($user, 'api')
            // 201 : la ressource d'abonnement est créée (comportement existant).
            ->postJson('/subscriptions/subscribe', ['userId' => $user->id, 'planId' => $plan->id])
            ->assertSuccessful();

        $this->getJson("/subscriptions/check/{$user->id}")
            ->assertOk()
            ->assertJsonPath('hasSubscription', true)
            ->assertJsonPath('planName', 'Offre gratuite')
            // C'est ce champ qui empêche l'offre gratuite d'ouvrir les pages
            // premium : `hasSubscription` seul ne les distinguait pas.
            ->assertJsonPath('tier', 'FREE');
    }

    public function test_check_reports_no_tier_without_subscription(): void
    {
        $user = $this->enterpriseUser();

        $this->getJson("/subscriptions/check/{$user->id}")
            ->assertOk()
            ->assertJsonPath('hasSubscription', false)
            ->assertJsonPath('tier', 'NONE');
    }

    public function test_a_paid_plan_cannot_be_activated_without_payment(): void
    {
        $user = $this->enterpriseUser();
        $plan = $this->paidPlan();

        $this->actingAs($user, 'api')
            ->postJson('/subscriptions/subscribe', ['userId' => $user->id, 'planId' => $plan->id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Ce plan est payant : son activation passe par le paiement.');

        $this->assertDatabaseCount('user_subscriptions', 0);
    }

    public function test_a_user_cannot_subscribe_on_behalf_of_someone_else(): void
    {
        $actor = $this->enterpriseUser();
        $victim = $this->enterpriseUser(['email' => 'autre@goriya-test.ci']);
        $plan = $this->freePlan();

        $this->actingAs($actor, 'api')
            ->postJson('/subscriptions/subscribe', ['userId' => $victim->id, 'planId' => $plan->id])
            ->assertStatus(403);

        $this->assertDatabaseCount('user_subscriptions', 0);
    }

    public function test_checkout_still_refuses_a_free_plan(): void
    {
        $user = $this->enterpriseUser();
        $plan = $this->freePlan();

        $this->actingAs($user, 'api')
            ->postJson('/subscriptions/checkout', ['userId' => $user->id, 'planId' => $plan->id])
            ->assertStatus(400);
    }
}
