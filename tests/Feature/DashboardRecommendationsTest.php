<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carte « Recommandations IA du jour » du tableau de bord entreprise —
 * remplace un texte figé par des phrases calculées sur les vraies
 * statistiques de l'entreprise (voir CompanyRecommendationService).
 */
class DashboardRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'Goriya Test SARL',
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);
    }

    private function enterprise(Company $company): User
    {
        return User::create([
            'name' => $company->name,
            'email' => 'contact@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);
    }

    public function test_un_candidat_ne_peut_pas_voir_les_recommandations_entreprise(): void
    {
        $candidat = User::create([
            'name' => 'Marie Dubois', 'email' => 'marie@example.ci',
            'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE',
        ]);

        $this->actingAs($candidat, 'api')->getJson('/dashboard/recommendations')->assertForbidden();
    }

    public function test_sans_aucune_donnee_les_trois_recommandations_restent_honnetes(): void
    {
        $company = $this->company();
        $enterprise = $this->enterprise($company);

        $res = $this->actingAs($enterprise, 'api')->getJson('/dashboard/recommendations')->assertOk()->json();

        $this->assertCount(3, $res['items']);
        $this->assertStringContainsString('Aucune candidature', $res['items'][0]);
        $this->assertStringContainsString('élargir votre vivier', $res['items'][2]);
    }

    public function test_la_croissance_des_candidatures_est_calculee_sur_le_score_reel(): void
    {
        $company = $this->company();
        $enterprise = $this->enterprise($company);
        $offre = JobOffer::create(['title' => 'Développeuse', 'company_id' => $company->id, 'status' => 'ACTIVE']);

        $candidatA = User::create(['name' => 'A', 'email' => 'a@example.ci', 'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE']);
        $candidatB = User::create(['name' => 'B', 'email' => 'b@example.ci', 'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE']);

        // Mois dernier : score moyen 50.
        Candidature::create([
            'candidate_name' => 'A', 'candidate_email' => 'a@example.ci', 'status' => 'EN_ATTENTE',
            'score' => 50, 'applied_date' => now()->subMonth()->startOfMonth()->addDays(2),
            'job_offer_id' => $offre->id, 'user_id' => $candidatA->id,
        ]);

        // Ce mois-ci : score moyen 75, soit +50%.
        Candidature::create([
            'candidate_name' => 'B', 'candidate_email' => 'b@example.ci', 'status' => 'EN_ATTENTE',
            'score' => 75, 'applied_date' => now()->startOfMonth()->addDay(),
            'job_offer_id' => $offre->id, 'user_id' => $candidatB->id,
        ]);

        $res = $this->actingAs($enterprise, 'api')->getJson('/dashboard/recommendations')->assertOk()->json();

        $this->assertStringContainsString('50%', $res['items'][0]);
        $this->assertStringContainsString('plus qualifiées', $res['items'][0]);
    }
}
