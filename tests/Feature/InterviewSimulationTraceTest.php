<?php

namespace Tests\Feature;

use App\Models\InterviewSession;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesActiveSubscription;
use Tests\TestCase;

/**
 * Trace d'une simulation d'entretien, telle que la page du candidat
 * l'enregistre : elle n'envoie que le poste visé au départ, puis le score, le
 * bilan et la durée à la fin. La session doit être créée au nom du compte
 * connecté, et son bilan complet conservé.
 */
class InterviewSimulationTraceTest extends TestCase
{
    use GivesActiveSubscription, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function candidate(string $email, string $role = 'USER'): User
    {
        $user = User::create(['name' => 'Awa Koné', 'email' => $email, 'password' => 'motdepasse-solide', 'role' => $role, 'status' => 'ACTIVE']);
        $this->giveActiveSubscription($user, 'Premium');

        return $user;
    }

    public function test_the_simulation_is_recorded_under_the_signed_in_candidate(): void
    {
        $awa = $this->candidate('awa@example.ci');

        // Départ : seul le poste est envoyé ; nom et email imposés par le compte,
        // même si la requête prétend autre chose.
        $id = $this->actingAs($awa, 'api')
            ->postJson('/interview-sessions', ['position' => 'Développeuse Full-Stack', 'candidateEmail' => 'koffi@example.ci', 'candidateName' => 'Koffi'])
            ->assertCreated()
            ->assertJsonPath('candidateName', 'Awa Koné')
            ->assertJsonPath('candidateEmail', 'awa@example.ci')
            ->assertJsonPath('position', 'Développeuse Full-Stack')
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('duration', 0)
            ->json('id');

        // Fin : score, durée et un bilan de 800 caractères (limite de la page).
        $summary = trim(str_repeat('Réponses structurées et exemples concrets. ', 19));
        $this->assertGreaterThan(255, mb_strlen($summary));
        $this->actingAs($awa, 'api')
            ->patchJson("/interview-sessions/{$id}", ['score' => 78, 'feedback' => $summary, 'duration' => 12, 'status' => 'COMPLETED', 'candidateEmail' => 'koffi@example.ci'])
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('score', 78)
            ->assertJsonPath('duration', 12);

        $session = InterviewSession::findOrFail($id);
        $this->assertSame($summary, $session->feedback);
        // Le propriétaire de la session ne change pas en cours de route.
        $this->assertSame('awa@example.ci', $session->candidate_email);

        // Elle apparaît dans l'historique du back-office.
        $this->app['auth']->forgetGuards();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.ci', 'password' => 'motdepasse-solide', 'role' => 'ADMIN', 'status' => 'ACTIVE']);
        $this->actingAs($admin, 'api')->getJson('/admin/interview-simulation/history')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_restarting_a_simulation_removes_only_the_candidates_own_session(): void
    {
        $awa = $this->candidate('awa@example.ci');
        $koffi = $this->candidate('koffi@example.ci');

        $mine = $this->actingAs($awa, 'api')->postJson('/interview-sessions', ['position' => 'Comptable'])->assertCreated()->json('id');
        $this->app['auth']->forgetGuards();
        $theirs = $this->actingAs($koffi, 'api')->postJson('/interview-sessions', ['position' => 'Juriste'])->assertCreated()->json('id');

        $this->app['auth']->forgetGuards();
        $this->actingAs($awa, 'api')->deleteJson("/interview-sessions/{$theirs}")->assertForbidden();
        $this->actingAs($awa, 'api')->deleteJson("/interview-sessions/{$mine}")->assertOk();

        $this->assertSame([$theirs], InterviewSession::pluck('id')->all());
    }
}
