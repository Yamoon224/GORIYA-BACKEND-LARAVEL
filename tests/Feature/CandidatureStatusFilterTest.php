<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtre par statut de l'écran « Mes candidatures » (standard/mes-offres).
 *
 * Les onglets envoyaient ACCEPTEE / REFUSEE, deux valeurs absentes de
 * App\Enums\CandidatureStatus : le filtre ne renvoyait jamais rien sur ces
 * onglets alors que des candidatures acceptées existaient. Ce test fige le
 * contrat — chaque valeur envoyée par le front doit ramener ses lignes.
 */
class CandidatureStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    /** Valeurs envoyées par les onglets de standard/app/(protected)/mes-offres. */
    private const ONGLETS = ['EN_ATTENTE', 'EN_COURS', 'APPROUVEE', 'REJETEE'];

    private function candidat(): User
    {
        return User::create([
            'name' => 'Marie Dubois',
            'email' => 'marie.dubois@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
    }

    /** Une candidature du candidat donné, dans le statut demandé. */
    private function candidature(User $candidat, string $status): Candidature
    {
        $company = Company::create([
            'name' => 'Goriya '.$status,
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);

        $offre = JobOffer::create([
            'title' => 'Poste '.$status,
            'company_id' => $company->id,
            'status' => 'ACTIVE',
        ]);

        return Candidature::create([
            'candidate_name' => $candidat->name,
            'candidate_email' => $candidat->email,
            'status' => $status,
            'score' => 0,
            'applied_date' => now(),
            'user_id' => $candidat->id,
            'job_offer_id' => $offre->id,
        ]);
    }

    public function test_each_tab_returns_its_own_applications(): void
    {
        $candidat = $this->candidat();
        foreach (self::ONGLETS as $status) {
            $this->candidature($candidat, $status);
        }

        foreach (self::ONGLETS as $status) {
            $reponse = $this->actingAs($candidat, 'api')
                ->getJson('/candidatures/paginate?limit=100&status='.$status)
                ->assertOk();

            $this->assertCount(1, $reponse->json('data'), "L'onglet {$status} ne renvoie rien.");
            $this->assertSame($status, $reponse->json('data.0.status'));
        }
    }

    public function test_the_all_tab_returns_every_application(): void
    {
        $candidat = $this->candidat();
        foreach (self::ONGLETS as $status) {
            $this->candidature($candidat, $status);
        }

        // L'onglet « Toutes » n'envoie pas de `status` du tout.
        $this->actingAs($candidat, 'api')
            ->getJson('/candidatures/paginate?limit=100')
            ->assertOk()
            ->assertJsonCount(count(self::ONGLETS), 'data');
    }

    public function test_the_default_page_size_no_longer_hides_applications(): void
    {
        $candidat = $this->candidat();
        for ($i = 0; $i < 12; $i++) {
            $this->candidature($candidat, 'EN_ATTENTE');
        }

        // Sans `limit`, le backend en renvoie 10 : c'est ce défaut qui tronquait
        // silencieusement la liste (l'écran n'a pas de pagination).
        $this->actingAs($candidat, 'api')
            ->getJson('/candidatures/paginate')
            ->assertOk()
            ->assertJsonCount(10, 'data');

        $this->actingAs($candidat, 'api')
            ->getJson('/candidatures/paginate?limit=100')
            ->assertOk()
            ->assertJsonCount(12, 'data');
    }

    public function test_a_candidate_only_sees_their_own_applications(): void
    {
        $candidat = $this->candidat();
        $this->candidature($candidat, 'EN_ATTENTE');

        $autre = User::create([
            'name' => 'Koffi Yao',
            'email' => 'koffi.yao@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
        $this->candidature($autre, 'EN_ATTENTE');

        $this->actingAs($candidat, 'api')
            ->getJson('/candidatures/paginate?limit=100')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
