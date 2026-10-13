<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les pages back-office "Matching IA Avancé" et "Scoring IA Avancé" restaient
 * à zéro en production : elles lisaient matching_results/scoring_results, des
 * tables qui ne reçoivent jamais d'écriture en usage normal. Elles projettent
 * maintenant les candidatures déjà scorées par CandidatureCompatibilityService
 * (candidatures.score) — ce test vérifie que les vraies candidatures
 * remontent dans les stats, la liste et la mise à jour de statut.
 */
class AdminMatchingScoringRealDataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Goriya', 'email' => 'admin@goriya.net',
            'password' => 'motdepasse-solide', 'role' => 'ADMIN', 'status' => 'ACTIVE',
        ]);
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'Goriya Test SARL', 'sector' => 'Technologie',
            'status' => 'ACTIVE', 'partnership_date' => '2026-01-01',
        ]);
    }

    public function test_les_candidatures_scorees_alimentent_les_stats_de_matching_et_scoring(): void
    {
        $admin = $this->admin();
        $company = $this->company();
        $offre = JobOffer::create(['title' => 'Développeuse Full-Stack', 'company_id' => $company->id, 'status' => 'ACTIVE']);

        $candidatA = User::create(['name' => 'Awa Koné', 'email' => 'awa@example.ci', 'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE']);
        $candidatB = User::create(['name' => 'Jean Kouassi', 'email' => 'jean@example.ci', 'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE']);
        $candidatC = User::create(['name' => 'Fatou Bamba', 'email' => 'fatou@example.ci', 'password' => 'motdepasse-solide', 'role' => 'USER', 'status' => 'ACTIVE']);

        $approuvee = Candidature::create([
            'candidate_name' => 'Awa Koné', 'candidate_email' => 'awa@example.ci', 'status' => 'APPROUVEE',
            'score' => 90, 'applied_date' => now(), 'job_offer_id' => $offre->id, 'user_id' => $candidatA->id,
        ]);
        Candidature::create([
            'candidate_name' => 'Jean Kouassi', 'candidate_email' => 'jean@example.ci', 'status' => 'EN_COURS',
            'score' => 70, 'applied_date' => now(), 'job_offer_id' => $offre->id, 'user_id' => $candidatB->id,
        ]);
        // Score pas encore calculé : ne doit compter ni comme match, ni comme score généré.
        Candidature::create([
            'candidate_name' => 'Fatou Bamba', 'candidate_email' => 'fatou@example.ci', 'status' => 'EN_ATTENTE',
            'score' => 0, 'applied_date' => now(), 'job_offer_id' => $offre->id, 'user_id' => $candidatC->id,
        ]);

        $api = $this->actingAs($admin, 'api');

        $api->getJson('/admin/matching/stats')->assertOk()
            ->assertJsonPath('data.totalMatches', 2)
            ->assertJsonPath('data.averageScore', 80)
            ->assertJsonPath('data.successRate', 50)
            ->assertJsonPath('data.pendingMatches', 1);

        $recent = $api->getJson('/admin/matching/recent?limit=5')->assertOk()->json();
        $this->assertCount(2, $recent['data']);
        $this->assertSame('Développeuse Full-Stack', $recent['data'][0]['position']);
        $this->assertSame('Goriya Test SARL', $recent['data'][0]['company']);

        $api->getJson('/admin/scoring/stats')->assertOk()
            ->assertJsonPath('data.generatedScores', 2)
            ->assertJsonPath('data.averageScore', 80)
            ->assertJsonPath('data.accuracy', 67);

        $recentScoring = $api->getJson('/admin/scoring/recent?limit=5')->assertOk()->json();
        $this->assertCount(2, $recentScoring['data']);

        // Finaliser le match met bien à jour la candidature réelle.
        $api->patchJson("/admin/matching/{$approuvee->id}/status", ['status' => 'FINALISE'])
            ->assertOk()
            ->assertJsonPath('data.status', 'FINALISE');

        $this->assertSame('APPROUVEE', $approuvee->fresh()->status->value);
    }
}
