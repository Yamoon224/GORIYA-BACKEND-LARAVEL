<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\Cv;
use App\Models\JobOffer;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profil du candidat exposé avec sa candidature.
 *
 * Les cartes de l'espace entreprise affichaient l'e-mail du candidat sous son
 * nom — une information de contact, pas un profil. Elles montrent désormais le
 * titre professionnel et les compétences, que la ressource doit donc fournir.
 * Aucune table ne porte ces compétences : elles viennent du portfolio et de
 * l'étape « Compétences » du créateur de CV, d'où la fusion vérifiée ici.
 */
class CandidateProfileOnCandidatureTest extends TestCase
{
    use RefreshDatabase;

    private function entreprise(): array
    {
        $company = Company::create([
            'name' => 'Goriya Test SARL',
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);

        $recruteur = User::create([
            'name' => 'Goriya Test SARL',
            'email' => 'contact@goriya-test.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);

        $offre = JobOffer::create([
            'title' => 'Développeur Full-Stack Senior',
            'company_id' => $company->id,
            'status' => 'ACTIVE',
        ]);

        return [$recruteur, $offre];
    }

    private function candidat(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Marie Dubois',
            'email' => 'marie.dubois@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'title' => 'Développeuse Full-Stack',
        ], $attributes));
    }

    private function postuler(User $candidat, JobOffer $offre): Candidature
    {
        return Candidature::create([
            'candidate_name' => $candidat->name,
            'candidate_email' => $candidat->email,
            'status' => 'EN_ATTENTE',
            'score' => 92,
            'applied_date' => '2026-01-15',
            'user_id' => $candidat->id,
            'job_offer_id' => $offre->id,
        ]);
    }

    public function test_la_liste_expose_le_titre_et_les_competences_du_candidat(): void
    {
        [$recruteur, $offre] = $this->entreprise();
        $candidat = $this->candidat();

        Portfolio::create([
            'title' => 'Portfolio de Marie',
            'description' => 'Projets React et Node.js',
            'created_date' => '2026-01-01',
            'user_id' => $candidat->id,
            'skills' => ['React', 'Node.js'],
        ]);

        Cv::create([
            'user_id' => $candidat->id,
            'step' => 3,
            // Le CV répète React : la carte ne doit pas afficher deux fois la
            // même compétence selon l'endroit où le candidat l'a saisie.
            'data' => ['competences' => [
                ['nom' => 'React', 'niveau' => 'Expert'],
                ['nom' => 'PostgreSQL', 'niveau' => 'Avancé'],
            ]],
        ]);

        $this->postuler($candidat, $offre);

        $reponse = $this->actingAs($recruteur, 'api')
            ->getJson('/candidatures/paginate?page=1&limit=10')
            ->assertOk();

        $reponse->assertJsonPath('data.0.candidateTitle', 'Développeuse Full-Stack');
        $this->assertSame(
            ['React', 'Node.js', 'PostgreSQL'],
            $reponse->json('data.0.candidateSkills')
        );
    }

    public function test_un_candidat_sans_portfolio_ni_cv_ne_casse_pas_la_carte(): void
    {
        [$recruteur, $offre] = $this->entreprise();
        $candidat = $this->candidat(['title' => null]);

        $this->postuler($candidat, $offre);

        $this->actingAs($recruteur, 'api')
            ->getJson('/candidatures/paginate?page=1&limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.candidateTitle', null)
            ->assertJsonPath('data.0.candidateSkills', []);
    }
}
