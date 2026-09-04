<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Actions de la barre de conversation : pièce jointe, épinglage, non lu et
 * suppression.
 *
 * Ces boutons existaient dans l'interface entreprise sans rien déclencher.
 * Le point délicat est la suppression : une conversation est partagée par deux
 * personnes, l'effacer côté entreprise ne doit rien retirer au candidat — d'où
 * les listes `deleted_by` / `starred_by` plutôt qu'un delete réel.
 */
class ConversationActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function conversation(): array
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

        $candidat = User::create([
            'name' => 'Marie Dubois',
            'email' => 'marie.dubois@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        $offre = JobOffer::create([
            'title' => 'Développeur Full-Stack Senior',
            'company_id' => $company->id,
            'status' => 'ACTIVE',
        ]);

        $candidature = Candidature::create([
            'candidate_name' => $candidat->name,
            'candidate_email' => $candidat->email,
            'status' => 'EN_ATTENTE',
            'score' => 92,
            'applied_date' => '2026-01-15',
            'user_id' => $candidat->id,
            'job_offer_id' => $offre->id,
        ]);

        $conversation = Conversation::create([
            'candidature_id' => $candidature->id,
            'participant_one_id' => $recruteur->id,
            'participant_two_id' => $candidat->id,
        ]);

        return [$recruteur, $candidat, $conversation];
    }

    public function test_un_message_peut_n_etre_qu_une_piece_jointe(): void
    {
        Storage::fake('public');
        [$recruteur, $candidat, $conversation] = $this->conversation();

        $this->actingAs($recruteur, 'api')
            ->post("/messages/conversations/{$conversation->id}/messages", [
                'attachment' => UploadedFile::fake()->create('contrat.pdf', 12, 'application/pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('content', '')
            ->assertJsonPath('attachment.name', 'contrat.pdf');

        // Le candidat lit la pièce jointe et son URL absolue, pas un contenu vide.
        $messages = $this->actingAs($candidat, 'api')
            ->getJson("/messages/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->json();

        $this->assertCount(1, $messages);
        $this->assertNotNull($messages[0]['attachment']['url']);

        // L'aperçu de la liste annonce le fichier, faute de texte à afficher.
        $this->actingAs($candidat, 'api')
            ->getJson('/messages/conversations')
            ->assertOk()
            ->assertJsonPath('0.lastMessage', 'Pièce jointe : contrat.pdf');
    }

    public function test_un_envoi_sans_texte_ni_fichier_est_refuse(): void
    {
        [$recruteur, , $conversation] = $this->conversation();

        // 400 et non 422 : bootstrap/app.php normalise les erreurs de
        // validation sur la forme {statusCode, message} du reste de l'API.
        $this->actingAs($recruteur, 'api')
            ->postJson("/messages/conversations/{$conversation->id}/messages", [])
            ->assertStatus(400);
    }

    public function test_l_epinglage_est_propre_a_chaque_participant(): void
    {
        [$recruteur, $candidat, $conversation] = $this->conversation();

        $this->actingAs($recruteur, 'api')
            ->putJson("/messages/conversations/{$conversation->id}/star", ['starred' => true])
            ->assertOk()
            ->assertJsonPath('starred', true);

        $this->actingAs($recruteur, 'api')
            ->getJson('/messages/conversations')
            ->assertJsonPath('0.starred', true);

        $this->actingAs($candidat, 'api')
            ->getJson('/messages/conversations')
            ->assertJsonPath('0.starred', false);

        // Sans `starred` explicite, l'appel bascule l'état courant.
        $this->actingAs($recruteur, 'api')
            ->putJson("/messages/conversations/{$conversation->id}/star")
            ->assertOk()
            ->assertJsonPath('starred', false);
    }

    public function test_la_suppression_ne_retire_la_conversation_qu_a_celui_qui_supprime(): void
    {
        [$recruteur, $candidat, $conversation] = $this->conversation();

        $this->actingAs($candidat, 'api')
            ->post("/messages/conversations/{$conversation->id}/messages", ['content' => 'Bonjour'])
            ->assertOk();

        $this->actingAs($recruteur, 'api')
            ->deleteJson("/messages/conversations/{$conversation->id}")
            ->assertOk();

        $this->actingAs($recruteur, 'api')->getJson('/messages/conversations')->assertJsonCount(0);
        $this->actingAs($candidat, 'api')->getJson('/messages/conversations')->assertJsonCount(1);

        // L'historique est intact : rien n'a été détruit, seule la liste change.
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_un_nouveau_message_fait_reapparaitre_une_conversation_supprimee(): void
    {
        [$recruteur, $candidat, $conversation] = $this->conversation();

        $this->actingAs($recruteur, 'api')
            ->deleteJson("/messages/conversations/{$conversation->id}")
            ->assertOk();

        $this->actingAs($recruteur, 'api')
            ->post("/messages/conversations/{$conversation->id}/messages", ['content' => 'Finalement…'])
            ->assertOk();

        $this->actingAs($recruteur, 'api')->getJson('/messages/conversations')->assertJsonCount(1);
        $this->actingAs($candidat, 'api')->getJson('/messages/conversations')->assertJsonCount(1);
    }

    public function test_marquer_non_lu_fait_revenir_la_pastille(): void
    {
        [$recruteur, $candidat, $conversation] = $this->conversation();

        $this->actingAs($candidat, 'api')
            ->post("/messages/conversations/{$conversation->id}/messages", ['content' => 'Bonjour'])
            ->assertOk();

        $this->actingAs($recruteur, 'api')
            ->putJson("/messages/conversations/{$conversation->id}/read")
            ->assertOk();

        $this->actingAs($recruteur, 'api')
            ->getJson('/messages/conversations')
            ->assertJsonPath('0.unreadCount', 0);

        $this->actingAs($recruteur, 'api')
            ->putJson("/messages/conversations/{$conversation->id}/unread")
            ->assertOk();

        $this->actingAs($recruteur, 'api')
            ->getJson('/messages/conversations')
            ->assertJsonPath('0.unreadCount', 1);
    }

    public function test_un_tiers_ne_peut_agir_sur_la_conversation(): void
    {
        [, , $conversation] = $this->conversation();

        $intrus = User::create([
            'name' => 'Intrus',
            'email' => 'intrus@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($intrus, 'api')
            ->deleteJson("/messages/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->actingAs($intrus, 'api')
            ->putJson("/messages/conversations/{$conversation->id}/star", ['starred' => true])
            ->assertForbidden();
    }

    public function test_une_notification_de_message_porte_le_lien_de_la_conversation(): void
    {
        [$recruteur, $candidat, $conversation] = $this->conversation();

        $this->actingAs($recruteur, 'api')
            ->post("/messages/conversations/{$conversation->id}/messages", ['content' => 'Bonjour Marie'])
            ->assertOk();

        $this->actingAs($candidat, 'api')
            ->getJson('/notifications')
            ->assertOk()
            ->assertJsonPath('0.link', "/messages?conversation={$conversation->id}");
    }
}
