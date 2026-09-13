<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesActiveSubscription;
use Tests\TestCase;

/**
 * Vérifie côté backend (pas seulement l'affichage frontend) que les
 * fonctionnalités réservées à un forfait supérieur refusent vraiment la
 * ressource — voir EnsurePlanIncludesFeature (middleware `plan.feature`) et
 * SubscriptionPlanSeeder pour la répartition par plan.
 *
 * Avant ce middleware, un compte Grouilleur (ou une entreprise Business)
 * pouvait utiliser ces fonctionnalités en appelant l'API directement : seul
 * le frontend (SubscriptionGate) décidait d'afficher ou non la page.
 */
class PlanFeatureGateTest extends TestCase
{
    use RefreshDatabase, GivesActiveSubscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
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
        $this->giveActiveSubscription($user, $planName);

        return $user;
    }

    private function enterpriseWithPlan(string $planName): User
    {
        $company = Company::create([
            'name' => 'Goriya Test SARL '.uniqid(),
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);
        $rh = User::create([
            'name' => 'RH Test',
            'email' => 'rh-'.uniqid().'@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);
        $this->giveActiveSubscription($rh, $planName);

        return $rh;
    }

    // --- USER : Simulation d'entretien (Premium uniquement) -----------------

    public function test_standard_cannot_start_an_interview_simulation(): void
    {
        $user = $this->userWithPlan('Standard');

        $this->actingAs($user, 'api')
            ->postJson('/interview-sessions', [])
            ->assertStatus(403);
    }

    public function test_premium_can_reach_the_interview_simulation_endpoint(): void
    {
        $user = $this->userWithPlan('Premium');

        $response = $this->actingAs($user, 'api')->postJson('/interview-sessions', []);

        // Pas de payload valide fourni : peu importe qu'elle échoue en 422,
        // l'important est qu'elle ne soit PAS bloquée par le plan (403).
        $this->assertNotSame(403, $response->status());
    }

    // --- USER : Recherche avancée sur une entreprise (Standard + Premium) ---

    public function test_grouilleur_cannot_use_company_research(): void
    {
        $user = $this->userWithPlan('Grouilleur');

        $this->actingAs($user, 'api')
            ->postJson('/research', [])
            ->assertStatus(403);
    }

    public function test_standard_can_reach_company_research(): void
    {
        $user = $this->userWithPlan('Standard');

        $response = $this->actingAs($user, 'api')->postJson('/research', []);

        $this->assertNotSame(403, $response->status());
    }

    // --- USER : Goriya Pitch / Goriya Docs (Premium uniquement) -------------

    public function test_standard_cannot_create_a_pitch(): void
    {
        $user = $this->userWithPlan('Standard');

        $this->actingAs($user, 'api')
            ->postJson('/pitches', [])
            ->assertStatus(403);
    }

    public function test_standard_cannot_create_a_presentation(): void
    {
        $user = $this->userWithPlan('Standard');

        $this->actingAs($user, 'api')
            ->postJson('/presentations', [])
            ->assertStatus(403);
    }

    public function test_a_user_with_no_active_subscription_is_blocked_everywhere(): void
    {
        $user = User::create([
            'name' => 'Sans Abonnement',
            'email' => 'sans-abonnement@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user, 'api')->postJson('/research', [])->assertStatus(403);
        $this->actingAs($user, 'api')->postJson('/pitches', [])->assertStatus(403);
    }

    // --- ENTREPRISE : Enquêtes internes / Gestion de paie (Business+) -------

    public function test_business_cannot_create_an_employee_survey(): void
    {
        $rh = $this->enterpriseWithPlan('Business');

        $this->actingAs($rh, 'api')
            ->postJson('/employee-surveys', [
                'title' => 'Satisfaction',
                'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
            ])
            ->assertStatus(403);
    }

    public function test_business_plus_can_create_an_employee_survey(): void
    {
        $rh = $this->enterpriseWithPlan('Business+');

        $this->actingAs($rh, 'api')
            ->postJson('/employee-surveys', [
                'title' => 'Satisfaction',
                'questions' => [['id' => 'q1', 'question' => 'Ça va ?', 'type' => 'RATING']],
            ])
            ->assertStatus(201);
    }

    public function test_the_free_enterprise_offer_cannot_run_payroll(): void
    {
        $rh = $this->enterpriseWithPlan('Offre gratuite');

        $this->actingAs($rh, 'api')
            ->postJson('/payroll/runs', ['year' => 2026, 'month' => 9])
            ->assertStatus(403);
    }

    public function test_business_cannot_run_payroll(): void
    {
        $rh = $this->enterpriseWithPlan('Business');

        $this->actingAs($rh, 'api')
            ->postJson('/payroll/runs', ['year' => 2026, 'month' => 9])
            ->assertStatus(403);
    }

    public function test_business_plus_can_run_payroll(): void
    {
        $rh = $this->enterpriseWithPlan('Business+');

        $this->actingAs($rh, 'api')
            ->postJson('/payroll/runs', ['year' => 2026, 'month' => 9])
            ->assertCreated();
    }

    public function test_business_cannot_update_payroll_settings(): void
    {
        $rh = $this->enterpriseWithPlan('Business');

        $this->actingAs($rh, 'api')
            ->putJson('/payroll/settings', [])
            ->assertStatus(403);
    }

    // --- Catalogue : les clés attendues sont bien seedées --------------------

    public function test_seeded_plans_carry_the_expected_included_features(): void
    {
        $this->assertSame([], SubscriptionPlan::where('name', 'Grouilleur')->firstOrFail()->included_features);
        $this->assertSame(
            ['goriya_meet', 'goriya_connect', 'recherche_entreprise'],
            SubscriptionPlan::where('name', 'Standard')->firstOrFail()->included_features,
        );
        $this->assertSame(
            ['goriya_meet'],
            SubscriptionPlan::where('name', 'Business')->firstOrFail()->included_features,
        );
        $this->assertSame(
            ['goriya_meet', 'enquetes_internes', 'gestion_paie'],
            SubscriptionPlan::where('name', 'Business+')->firstOrFail()->included_features,
        );
    }
}
