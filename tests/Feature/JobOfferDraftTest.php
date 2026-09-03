<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Brouillons d'offres d'emploi.
 *
 * Une entreprise doit pouvoir enregistrer une offre incomplète, la retrouver,
 * la compléter puis la publier. Deux garde-fous encadrent ce cycle :
 *  - un brouillon reste invisible hors de l'entreprise qui l'a écrit ;
 *  - la publication, elle, exige une offre complète.
 *
 * Codes de retour : l'application réplique le format NestJS (voir
 * bootstrap/app.php) — une validation refusée sort en 400, et le refus de
 * publier une offre incomplète en 422.
 */
class JobOfferDraftTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'Goriya Test SARL'): Company
    {
        return Company::create([
            'name' => $name,
            'sector' => 'Technologie',
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.ci',
            'status' => 'ACTIVE',
            // Colonne NOT NULL sans défaut sur `companies`.
            'partnership_date' => '2026-01-01',
        ]);
    }

    private function enterpriseUser(Company $company): User
    {
        return User::create([
            'name' => $company->name,
            'email' => 'user-'.$company->id.'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);
    }

    /**
     * Repasse en visiteur anonyme : `actingAs` vaut pour toutes les requêtes
     * suivantes du test, il faut le défaire explicitement pour vérifier ce que
     * voit le public.
     */
    private function deconnecter(): void
    {
        Auth::forgetGuards();
    }

    /** Offre complète, telle que l'envoie le formulaire au moment de publier. */
    private function offreComplete(Company $company, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Développeur Full-Stack Senior',
            'location' => 'Abidjan, Côte d\'Ivoire',
            'type' => 'CDI',
            'experience' => 'SENIOR',
            'salary' => '450000 - 650000 FCFA',
            'description' => 'Vous rejoignez une équipe produit de six personnes.',
            'benefits' => 'Télétravail partiel, mutuelle, formation continue.',
            'requirements' => ['React', 'PHP'],
            'publishDate' => '2026-09-10',
            'endDate' => '2026-10-10',
            'companyId' => $company->id,
        ], $overrides);
    }

    public function test_an_incomplete_offer_can_be_saved_as_a_draft(): void
    {
        $company = $this->company();
        $user = $this->enterpriseUser($company);

        $this->actingAs($user, 'api')
            ->postJson('/job-offers', [
                'title' => 'Poste à préciser',
                'status' => 'DRAFT',
                'companyId' => $company->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('title', 'Poste à préciser')
            ->assertJsonPath('type', null)
            ->assertJsonPath('endDate', null);
    }

    public function test_a_draft_needs_a_title(): void
    {
        $company = $this->company();
        $user = $this->enterpriseUser($company);

        $this->actingAs($user, 'api')
            ->postJson('/job-offers', ['status' => 'DRAFT', 'companyId' => $company->id])
            ->assertStatus(400);
    }

    public function test_an_incomplete_offer_cannot_be_published_directly(): void
    {
        $company = $this->company();
        $user = $this->enterpriseUser($company);

        // Champs exigés dès la validation de la requête, puisque le statut
        // demandé n'est pas DRAFT.
        $this->actingAs($user, 'api')
            ->postJson('/job-offers', [
                'title' => 'Poste à préciser',
                'status' => 'ACTIVE',
                'companyId' => $company->id,
            ])
            ->assertStatus(400);

        $this->assertSame(0, JobOffer::count());
    }

    public function test_a_draft_is_completed_then_published(): void
    {
        $company = $this->company();
        $user = $this->enterpriseUser($company);

        $brouillon = $this->actingAs($user, 'api')
            ->postJson('/job-offers', [
                'title' => 'Développeur Full-Stack Senior',
                'status' => 'DRAFT',
                'companyId' => $company->id,
            ])
            ->assertOk()
            ->json('id');

        // Publier un brouillon encore vide est refusé, et le statut ne bouge pas.
        $this->actingAs($user, 'api')
            ->patchJson("/job-offers/{$brouillon}", ['status' => 'ACTIVE'])
            ->assertStatus(422);
        $this->assertSame('DRAFT', JobOffer::find($brouillon)->status->value);

        // Le brouillon se modifie sans contrainte tant qu'il reste brouillon.
        $this->actingAs($user, 'api')
            ->patchJson("/job-offers/{$brouillon}", ['location' => 'Abidjan, Côte d\'Ivoire'])
            ->assertOk()
            ->assertJsonPath('status', 'DRAFT');

        $this->actingAs($user, 'api')
            ->patchJson("/job-offers/{$brouillon}", array_merge(
                $this->offreComplete($company),
                ['status' => 'ACTIVE'],
            ))
            ->assertOk()
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('type', 'CDI');
    }

    public function test_a_complete_offer_is_published_in_one_step(): void
    {
        $company = $this->company();
        $user = $this->enterpriseUser($company);

        $this->actingAs($user, 'api')
            ->postJson('/job-offers', $this->offreComplete($company))
            // Sans `status`, la colonne prend son défaut : l'offre est publiée.
            ->assertOk()
            ->assertJsonPath('status', 'ACTIVE');
    }

    public function test_a_draft_is_hidden_from_the_public_job_search(): void
    {
        $company = $this->company();
        $user = $this->enterpriseUser($company);

        $brouillon = $this->actingAs($user, 'api')
            ->postJson('/job-offers', [
                'title' => 'Recrutement confidentiel',
                'status' => 'DRAFT',
                'companyId' => $company->id,
            ])
            ->json('id');

        // L'entreprise qui l'a écrit le retrouve dans ses annonces.
        $this->actingAs($user, 'api')
            ->getJson('/job-offers/paginate?companyId='.$company->id)
            ->assertOk()
            ->assertJsonFragment(['title' => 'Recrutement confidentiel']);
        $this->actingAs($user, 'api')
            ->getJson("/job-offers/{$brouillon}")
            ->assertOk();

        // Visiteur non authentifié : ni dans la liste, ni en accès direct.
        $this->deconnecter();
        $this->getJson('/job-offers/paginate')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Recrutement confidentiel']);
        $this->getJson('/job-offers')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Recrutement confidentiel']);
        $this->getJson("/job-offers/{$brouillon}")->assertStatus(404);
    }

    public function test_a_draft_is_hidden_from_another_company(): void
    {
        $auteur = $this->company('Auteur SARL');
        $tiers = $this->company('Concurrent SARL');
        $utilisateurAuteur = $this->enterpriseUser($auteur);
        $utilisateurTiers = $this->enterpriseUser($tiers);

        $brouillon = $this->actingAs($utilisateurAuteur, 'api')
            ->postJson('/job-offers', [
                'title' => 'Recrutement confidentiel',
                'status' => 'DRAFT',
                'companyId' => $auteur->id,
            ])
            ->json('id');

        $this->deconnecter();

        $this->actingAs($utilisateurTiers, 'api')
            ->getJson('/job-offers/paginate')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Recrutement confidentiel']);
        $this->actingAs($utilisateurTiers, 'api')
            ->getJson("/job-offers/{$brouillon}")
            ->assertStatus(404);
    }

    public function test_a_published_offer_stays_visible_to_everyone(): void
    {
        $company = $this->company();
        $user = $this->enterpriseUser($company);

        $this->actingAs($user, 'api')
            ->postJson('/job-offers', $this->offreComplete($company))
            ->assertOk();

        $this->deconnecter();
        $this->getJson('/job-offers/paginate')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Développeur Full-Stack Senior']);
    }
}
