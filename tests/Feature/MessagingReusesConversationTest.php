<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un même candidat qui postule deux fois chez la même entreprise doit
 * retrouver le même fil de discussion plutôt que d'en ouvrir un second — voir
 * MessagingService::findOrCreateForCandidature et la migration de fusion des
 * doublons historiques (2026_10_06_merge_duplicate_conversations).
 */
class MessagingReusesConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_seconde_candidature_du_meme_candidat_reutilise_la_conversation(): void
    {
        $company = Company::create([
            'name' => 'Goriya Test SARL',
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);

        // Le recruteur : résolu côté service via jobOffer->company->users->first().
        User::create([
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

        $offreA = JobOffer::create(['title' => 'Développeuse Full-Stack', 'company_id' => $company->id, 'status' => 'ACTIVE']);
        $offreB = JobOffer::create(['title' => 'Développeuse Mobile', 'company_id' => $company->id, 'status' => 'ACTIVE']);

        $candidatureA = Candidature::create([
            'candidate_name' => $candidat->name,
            'candidate_email' => $candidat->email,
            'status' => 'EN_ATTENTE',
            'score' => 90,
            'applied_date' => '2026-01-15',
            'user_id' => $candidat->id,
            'job_offer_id' => $offreA->id,
        ]);

        $candidatureB = Candidature::create([
            'candidate_name' => $candidat->name,
            'candidate_email' => $candidat->email,
            'status' => 'EN_ATTENTE',
            'score' => 88,
            'applied_date' => '2026-02-01',
            'user_id' => $candidat->id,
            'job_offer_id' => $offreB->id,
        ]);

        $resA = $this->actingAs($candidat, 'api')
            ->postJson('/messages/conversations', ['candidatureId' => $candidatureA->id])
            ->assertOk()
            ->json();

        $resB = $this->actingAs($candidat, 'api')
            ->postJson('/messages/conversations', ['candidatureId' => $candidatureB->id])
            ->assertOk()
            ->json();

        $this->assertSame($resA['id'], $resB['id']);
        $this->assertSame(1, Conversation::query()->count());
    }
}
