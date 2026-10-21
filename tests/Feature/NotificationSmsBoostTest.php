<?php

namespace Tests\Feature;

use App\Enums\CandidatureStatus;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PaymentGatewayManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Même porte que NotificationEmailBoostTest, pour le canal SMS : ÉLEVÉ
 * (Standard, Premium, Business+) double la notification in-app par un SMS
 * (en plus de l'email) quand l'utilisateur a un numéro ; FAIBLE reste
 * in-app seulement. Voir NotificationService::maybeSms().
 */
class NotificationSmsBoostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);

        config([
            'services.mtarget.api_key' => 'test-key',
            'services.mtarget.base_url' => 'https://api.mtarget.fr',
            'services.mtarget.sender' => 'GORIYA',
            'services.mtarget.default_country_code' => '225',
        ]);

        $this->mock(PaymentGatewayManager::class, function ($mock) {
            $mock->shouldReceive('supportsHostedCheckout')->andReturn(false);
            $mock->shouldReceive('resolve')->andReturnSelf();
            $mock->shouldReceive('verifyTransaction')->andReturn(['status' => 'SUCCESS']);
        });
    }

    private function fakeMtargetAccepted(): void
    {
        Http::fake([
            'api.mtarget.fr/*' => Http::response([
                'results' => [['msisdn' => '+2250700000000', 'smscount' => 1, 'code' => 0, 'reason' => 'ACCEPTED', 'ticket' => 't-1']],
            ]),
        ]);
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

    public function test_a_standard_subscriber_with_a_phone_also_gets_a_sms(): void
    {
        $this->fakeMtargetAccepted();

        $candidat = User::create([
            'name' => 'Marie Dubois',
            'email' => 'marie@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'phone' => '0700000000',
        ]);
        $this->subscribeToPaidPlan($candidat, 'Standard');
        $candidature = $this->candidatureFor($candidat);
        $candidature->status = CandidatureStatus::APPROUVEE;

        app(NotificationService::class)->notifyApplicationStatusChanged($candidature);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.mtarget.fr/messages'
            && $request->data()['msisdn'] === '+225700000000');
    }

    public function test_a_standard_subscriber_without_a_phone_gets_no_sms(): void
    {
        $this->fakeMtargetAccepted();

        $candidat = User::create([
            'name' => 'Fatou Bamba',
            'email' => 'fatou@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $this->subscribeToPaidPlan($candidat, 'Standard');
        $candidature = $this->candidatureFor($candidat);
        $candidature->status = CandidatureStatus::APPROUVEE;

        app(NotificationService::class)->notifyApplicationStatusChanged($candidature);

        Http::assertNothingSent();
    }

    public function test_a_grouilleur_user_with_a_phone_does_not_get_a_sms(): void
    {
        $this->fakeMtargetAccepted();

        $candidat = User::create([
            'name' => 'Jean Kouassi',
            'email' => 'jean@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'phone' => '0700000001',
        ]);
        $freePlan = SubscriptionPlan::where('name', 'Grouilleur')->firstOrFail();
        $this->actingAs($candidat, 'api')->postJson('/subscriptions/subscribe', [
            'userId' => $candidat->id,
            'planId' => $freePlan->id,
        ])->assertSuccessful();

        $candidature = $this->candidatureFor($candidat);
        $candidature->status = CandidatureStatus::APPROUVEE;

        app(NotificationService::class)->notifyApplicationStatusChanged($candidature);

        Http::assertNothingSent();
    }
}
