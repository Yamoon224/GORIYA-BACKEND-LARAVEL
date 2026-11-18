<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Un abonnement dont la date de fin est passée n'ouvre plus aucun accès et
 * passe en EXPIRED via le cron quotidien.
 */
class SubscriptionExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00');
    }

    private function subscription(string $email, string $endDate): UserSubscription
    {
        $user = User::create([
            'name' => 'Candidat Test',
            'email' => $email,
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $plan = SubscriptionPlan::first() ?? SubscriptionPlan::create([
            'name' => 'Premium',
            'price' => 4999,
            'billing_period' => 'MONTHLY',
            'user_type' => 'USER',
            'is_active' => true,
            'features' => [],
        ]);

        return UserSubscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE,
            'start_date' => '2026-10-05',
            'end_date' => $endDate,
            'period_months' => 1,
            'auto_renew' => false,
        ]);
    }

    public function test_overdue_subscription_grants_no_access_even_before_cron(): void
    {
        $sub = $this->subscription('a@example.com', '2026-11-05 00:00:00');

        $repo = app(UserSubscriptionRepositoryInterface::class);

        $this->assertNull($repo->findActiveForUser($sub->user_id));
    }

    public function test_command_expires_only_overdue_subscriptions(): void
    {
        $overdue = $this->subscription('a@example.com', '2026-11-05 00:00:00');
        $valid = $this->subscription('b@example.com', '2026-12-05 00:00:00');

        $this->artisan('subscriptions:expire-overdue')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::EXPIRED, $overdue->fresh()->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $valid->fresh()->status);
        $this->assertNotNull(app(UserSubscriptionRepositoryInterface::class)->findActiveForUser($valid->user_id));
    }

    public function test_cron_route_requires_secret(): void
    {
        config(['services.cron.secret' => 'secret-test']);
        $overdue = $this->subscription('a@example.com', '2026-11-05 00:00:00');

        $this->getJson('/cron/subscriptions/expire')->assertStatus(401);
        $this->getJson('/cron/subscriptions/expire?token=mauvais')->assertStatus(401);
        $this->assertSame(SubscriptionStatus::ACTIVE, $overdue->fresh()->status);

        $this->getJson('/cron/subscriptions/expire?token=secret-test')
            ->assertOk()
            ->assertJson(['expired' => 1]);
        $this->assertSame(SubscriptionStatus::EXPIRED, $overdue->fresh()->status);
    }

    public function test_cron_route_is_disabled_without_configured_secret(): void
    {
        config(['services.cron.secret' => null]);

        $this->getJson('/cron/subscriptions/expire')->assertStatus(401);
        $this->getJson('/cron/subscriptions/expire?token=')->assertStatus(401);
    }
}
