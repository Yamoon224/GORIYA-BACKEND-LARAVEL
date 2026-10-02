<?php

namespace Tests\Feature;

use App\Contracts\AiAnalysisServiceInterface;
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

    /**
     * Compatibilité IA : le score est calculé à partir du profil réel du
     * candidat et enregistré sur la candidature. Sans IA disponible, rien
     * n'est inventé — la candidature reste sans score.
     */
    public function test_la_compatibilite_ia_est_calculee_a_partir_du_profil_et_enregistree(): void
    {
        [$recruteur, $offre] = $this->entreprise();
        $candidat = $this->candidat();
        $candidature = $this->postuler($candidat, $offre);
        $candidature->update(['score' => 0, 'cover_letter' => 'Cinq ans de React.']);

        $ia = $this->mock(AiAnalysisServiceInterface::class);
        $ia->shouldReceive('scoreCompatibility')
            ->once()
            ->withArgs(fn (array $profil, array $poste) => $profil['title'] === 'Développeuse Full-Stack'
                && $profil['coverLetter'] === 'Cinq ans de React.'
                && $poste['title'] === 'Développeur Full-Stack Senior')
            ->andReturn(84);

        $this->actingAs($recruteur, 'api')
            ->postJson("/candidatures/{$candidature->id}/compatibility")
            ->assertOk()
            ->assertJsonPath('score', 84);

        $this->assertSame(84, (int) $candidature->refresh()->score);

        // Déjà calculé : pas de second appel IA (le mock n'en accepte qu'un).
        $this->actingAs($recruteur, 'api')
            ->postJson("/candidatures/{$candidature->id}/compatibility")
            ->assertOk()
            ->assertJsonPath('score', 84);
    }

    /** Le score est calculé dès le dépôt, après l'envoi de la réponse. */
    public function test_postuler_declenche_le_calcul_de_la_compatibilite(): void
    {
        [, $offre] = $this->entreprise();
        $candidat = $this->candidat();

        $this->mock(AiAnalysisServiceInterface::class)
            ->shouldReceive('scoreCompatibility')->once()->andReturn(67);

        $this->actingAs($candidat, 'api')
            ->postJson("/job-offers/{$offre->id}/apply", [])
            ->assertSuccessful();

        $this->assertSame(67, (int) Candidature::where('user_id', $candidat->id)->value('score'));
    }

    public function test_la_compatibilite_ia_indisponible_ne_fabrique_pas_de_score(): void
    {
        [$recruteur, $offre] = $this->entreprise();
        $candidature = $this->postuler($this->candidat(), $offre);
        $candidature->update(['score' => 0]);

        $this->mock(AiAnalysisServiceInterface::class)
            ->shouldReceive('scoreCompatibility')->once()->andReturn(null);

        $this->actingAs($recruteur, 'api')
            ->postJson("/candidatures/{$candidature->id}/compatibility")
            ->assertOk()
            ->assertJsonPath('score', null);

        $this->assertSame(0, (int) $candidature->refresh()->score);
    }

    /**
     * « Évaluation IA » : jugée sur le même dossier réel que le score de la
     * carte, enrichi des notes du recruteur.
     */
    public function test_l_evaluation_ia_s_appuie_sur_le_dossier_reel_du_candidat(): void
    {
        [$recruteur, $offre] = $this->entreprise();
        $candidature = $this->postuler($this->candidat(), $offre);
        $candidature->update(['cover_letter' => 'Cinq ans de React.']);

        $this->mock(AiAnalysisServiceInterface::class)
            ->shouldReceive('assessCandidate')
            ->once()
            ->withArgs(fn (array $profil, array $poste, string $notes) => $profil['title'] === 'Développeuse Full-Stack'
                && $profil['coverLetter'] === 'Cinq ans de React.'
                && $poste['title'] === 'Développeur Full-Stack Senior'
                && $poste['company'] === 'Goriya Test SARL'
                && $notes === 'Très à l\'aise à l\'oral.')
            ->andReturn([
                'technicalScore' => 80,
                'softSkillsScore' => 70,
                'culturalFitScore' => 60,
                'feedback' => 'Profil solide.',
                'questions' => [['question' => 'Parlez-nous de React.', 'type' => 'TECHNIQUE']],
            ]);

        $this->actingAs($recruteur, 'api')
            ->postJson("/candidatures/{$candidature->id}/assessment", ['exchangeNotes' => 'Très à l\'aise à l\'oral.'])
            ->assertSuccessful()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('technicalScore', 80)
            ->assertJsonPath('overallScore', 70)
            ->assertJsonPath('softSkillsFeedback', 'Profil solide.')
            ->assertJsonPath('skillsTest.0.question', 'Parlez-nous de React.');
    }

    public function test_l_evaluation_ia_indisponible_echoue_sans_inventer_de_scores(): void
    {
        [$recruteur, $offre] = $this->entreprise();
        $candidature = $this->postuler($this->candidat(), $offre);

        $this->mock(AiAnalysisServiceInterface::class)
            ->shouldReceive('assessCandidate')->once()->andReturn(null);

        $this->actingAs($recruteur, 'api')
            ->postJson("/candidatures/{$candidature->id}/assessment")
            ->assertSuccessful()
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('overallScore', null);
    }

    public function test_la_compatibilite_ia_est_reservee_a_l_entreprise_de_l_offre(): void
    {
        [, $offre] = $this->entreprise();
        $candidat = $this->candidat();
        $candidature = $this->postuler($candidat, $offre);

        $this->actingAs($candidat, 'api')
            ->postJson("/candidatures/{$candidature->id}/compatibility")
            ->assertForbidden();
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
