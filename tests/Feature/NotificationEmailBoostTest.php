<?php

namespace Tests\Feature;

use App\Enums\CandidatureStatus;
use App\Mail\NotificationMail;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PaymentGatewayManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Notification (d'offre) prioritaire" : ÉLEVÉ (Standard, Premium,
 * Business+) double la notification in-app par un email (NotificationMail) ;
 * FAIBLE (Grouilleur, Business, aucun abonnement) reste in-app seulement.
 * Voir NotificationService::maybeEmail().
 */
class NotificationEmailBoostTest extends TestCase
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

    private function candidatureFor(User $candidat): Candidature
    {
        $company = Company::create([
            'name' => 'Goriya Test SARL',
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);
        $offer = JobOffer::create(['title' => 'Développeuse Full-Stack', 'type' => 'CDI', 'company_id' => $company->id, 'status' => 'ACTIVE']);

        return Candidature::create([
            'candidate_name' => $candidat->name,
            'candidate_email' => $candidat->email,
            'status' => 'EN_ATTENTE',
            'score' => 80,
            'applied_date' => '2026-09-01',
            'user_id' => $candidat->id,
            'job_offer_id' => $offer->id,
        ]);
    }

    private function subscribeToPaidPlan(User $user, string $planName): void
    {
        $plan = SubscriptionPlan::where('name', $planName)->firstOrFail();
        $checkout = $this->actingAs($user, 'api')->postJson('/subscriptions/checkout', [
            'userId' => $user->id,
            'planId' => $plan->id,
            'gateway' => 'kkiapay',
        ])->assertOk();

        $txnId = $checkout->json('clientReference');
        $this->actingAs($user, 'api')
            ->getJson("/subscriptions/checkout/verify/{$txnId}?userId={$user->id}&planId={$plan->id}&gateway=kkiapay")
            ->assertSuccessful();
    }

    public function test_a_standard_subscriber_also_gets_an_email(): void
    {
        Mail::fake();

        $candidat = User::create([
            'name' => 'Marie Dubois',
            'email' => 'marie@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $this->subscribeToPaidPlan($candidat, 'Standard');
        $candidature = $this->candidatureFor($candidat);
        $candidature->status = CandidatureStatus::APPROUVEE;

        app(NotificationService::class)->notifyApplicationStatusChanged($candidature);

        Mail::assertQueued(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($candidat->email));
    }

    public function test_a_grouilleur_user_does_not_get_an_email(): void
    {
        Mail::fake();

        $candidat = User::create([
            'name' => 'Jean Kouassi',
            'email' => 'jean@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $freePlan = SubscriptionPlan::where('name', 'Grouilleur')->firstOrFail();
        $this->actingAs($candidat, 'api')->postJson('/subscriptions/subscribe', [
            'userId' => $candidat->id,
            'planId' => $freePlan->id,
        ])->assertSuccessful();

        $candidature = $this->candidatureFor($candidat);
        $candidature->status = CandidatureStatus::APPROUVEE;

        app(NotificationService::class)->notifyApplicationStatusChanged($candidature);

        Mail::assertNotQueued(NotificationMail::class);
    }

    public function test_a_candidate_without_any_subscription_does_not_get_an_email(): void
    {
        Mail::fake();

        $candidat = User::create([
            'name' => 'Awa Traoré',
            'email' => 'awa@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $candidature = $this->candidatureFor($candidat);
        $candidature->status = CandidatureStatus::APPROUVEE;

        app(NotificationService::class)->notifyApplicationStatusChanged($candidature);

        Mail::assertNotQueued(NotificationMail::class);
    }
}
