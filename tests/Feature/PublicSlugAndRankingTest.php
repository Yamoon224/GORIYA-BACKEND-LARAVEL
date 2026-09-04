<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobOffer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * URLs publiques des fiches entreprise / offre et ordre de la liste d'offres.
 *
 * Deux promesses sont vérifiées ici :
 *  - une fiche s'ouvre par son slug, et continue de s'ouvrir par son uuid
 *    (les liens partagés avant la mise en place des slugs restent valides) ;
 *  - la liste publique remonte d'abord les offres des entreprises au forfait
 *    le plus élevé, puis les plus récentes.
 */
class PublicSlugAndRankingTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::create([
            'name' => $name,
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);
    }

    private function offer(Company $company, string $title, string $createdAt): JobOffer
    {
        $offre = JobOffer::create([
            'title' => $title,
            'company_id' => $company->id,
            'status' => 'ACTIVE',
        ]);

        // `created_at` est renseigné par les timestamps : on le repositionne
        // pour pouvoir tester l'ordre par date d'ajout.
        $offre->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $offre->refresh();
    }

    private function subscribe(Company $company, string $planName, float $price): void
    {
        $plan = SubscriptionPlan::create([
            'name' => $planName,
            'price' => $price,
            'billing_period' => 'MONTHLY',
            'user_type' => 'ENTREPRISE',
            'features' => [],
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => $company->name,
            'email' => 'rh-'.$company->id.'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);

        UserSubscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'ACTIVE',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
        ]);
    }

    public function test_le_slug_est_derive_du_nom_et_dedoublonne(): void
    {
        $premiere = $this->company('Goriya Test SARL');
        $seconde = $this->company('Goriya Test SARL');

        $this->assertSame('goriya-test-sarl', $premiere->slug);
        $this->assertSame('goriya-test-sarl-2', $seconde->slug);

        $offre = $this->offer($premiere, 'Développeur Full Stack', '2026-01-10 09:00:00');
        $this->assertSame('developpeur-full-stack', $offre->slug);
    }

    public function test_le_slug_ne_bouge_pas_quand_le_nom_change(): void
    {
        $entreprise = $this->company('Goriya Test SARL');
        $entreprise->update(['name' => 'Goriya Renommée']);

        $this->assertSame('goriya-test-sarl', $entreprise->fresh()->slug);
    }

    public function test_les_fiches_repondent_au_slug_comme_a_l_uuid(): void
    {
        $entreprise = $this->company('Goriya Test SARL');
        $offre = $this->offer($entreprise, 'Développeur Full Stack', '2026-01-10 09:00:00');

        // Les resources sont sérialisées sans enveloppe `data`
        // (JsonResource::withoutWrapping, voir AppServiceProvider).
        $this->getJson('/companies/'.$entreprise->slug)->assertOk()->assertJsonPath('id', $entreprise->id);
        $this->getJson('/companies/'.$entreprise->id)->assertOk()->assertJsonPath('slug', $entreprise->slug);
        $this->getJson('/job-offers/'.$offre->slug)->assertOk()->assertJsonPath('id', $offre->id);
        $this->getJson('/job-offers/'.$offre->id)->assertOk()->assertJsonPath('slug', $offre->slug);
    }

    public function test_un_slug_inconnu_repond_404(): void
    {
        $this->getJson('/companies/entreprise-qui-nexiste-pas')->assertNotFound();
        $this->getJson('/job-offers/offre-qui-nexiste-pas')->assertNotFound();
    }

    public function test_les_offres_sortent_par_forfait_puis_par_date_decroissante(): void
    {
        $abonnee = $this->company('Entreprise Business Plus');
        $this->subscribe($abonnee, 'Business+ Test', 351900);

        $sansAbonnement = $this->company('Entreprise Sans Forfait');

        // La plus ancienne des trois, mais celle d'une entreprise abonnée.
        $boostee = $this->offer($abonnee, 'Offre Boostée', '2026-01-01 08:00:00');
        $ancienne = $this->offer($sansAbonnement, 'Offre Ancienne', '2026-02-01 08:00:00');
        $recente = $this->offer($sansAbonnement, 'Offre Récente', '2026-03-01 08:00:00');

        $ids = collect($this->getJson('/job-offers/paginate?limit=10')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$boostee->id, $recente->id, $ancienne->id], $ids);
    }
}
