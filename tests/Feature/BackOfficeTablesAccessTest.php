<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CvAnalysis;
use App\Models\InterviewSession;
use App\Models\MatchingResult;
use App\Models\ScoringResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les tables du back-office (scoring, matching, simulations d'entretien,
 * journal des analyses de CV, planning) portent le nom et l'email de tous les
 * candidats : un compte candidat ou entreprise ne doit ni les lire, ni les
 * modifier, ni les supprimer. Un candidat garde la main sur sa propre session
 * de simulation, et sur elle seule.
 */
class BackOfficeTablesAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'USER', ?string $email = null): User
    {
        return User::create([
            'name' => 'Utilisateur '.Str::random(5),
            'email' => $email ?? Str::lower(Str::random(8)).'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => $role,
            'status' => 'ACTIVE',
        ]);
    }

    private function simulation(string $email): InterviewSession
    {
        return InterviewSession::create([
            'candidate_name' => 'Awa Koné',
            'candidate_email' => $email,
            'position' => 'Développeuse',
            'duration' => 30,
            'status' => 'ACTIVE',
            'start_time' => now(),
        ]);
    }

    /** @return array<string, string> ressource => identifiant d'une ligne */
    private function rows(): array
    {
        return [
            'scoring-results' => ScoringResult::create(['candidate_name' => 'Awa Koné', 'candidate_email' => 'awa@example.ci', 'position' => 'Développeuse', 'overall_score' => 80, 'criteria' => [], 'analysis_date' => now(), 'status' => 'COMPLETED'])->id,
            'matching-results' => MatchingResult::create(['candidate_name' => 'Awa Koné', 'candidate_email' => 'awa@example.ci', 'position' => 'Développeuse', 'company' => 'Goriya', 'matching_score' => 80, 'status' => 'NOUVEAU', 'match_date' => now()])->id,
            'cv-analysis' => CvAnalysis::create(['filename' => 'cv-awa-kone.pdf', 'analysis_score' => 80, 'upload_date' => now(), 'status' => 'COMPLETED'])->id,
            'calendar-events' => CalendarEvent::create(['title' => 'Entretien Awa Koné', 'type' => 'ENTRETIEN', 'start_time' => now(), 'end_time' => now()->addHour(), 'status' => 'CONFIRMED'])->id,
        ];
    }

    public function test_only_an_administrator_reads_or_changes_the_back_office_tables(): void
    {
        $rows = $this->rows();
        $this->simulation('awa@example.ci');

        foreach ([$this->user('USER'), $this->user('ENTREPRISE')] as $account) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($account, 'api');

            foreach ($rows as $resource => $id) {
                $this->getJson("/{$resource}")->assertForbidden();
                $this->getJson("/{$resource}/paginate")->assertForbidden();
                $this->getJson("/{$resource}/{$id}")->assertForbidden();
                $this->patchJson("/{$resource}/{$id}", [])->assertForbidden();
                $this->deleteJson("/{$resource}/{$id}")->assertForbidden();
            }
            $this->getJson('/interview-sessions')->assertForbidden();
            $this->getJson('/interview-sessions/paginate')->assertForbidden();
        }

        // Rien n'a été supprimé par les tentatives ci-dessus.
        $this->assertSame(1, ScoringResult::count());
        $this->assertSame(1, CalendarEvent::count());

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user('ADMIN'), 'api');
        foreach (array_keys($rows) as $resource) {
            $this->getJson("/{$resource}")->assertOk()->assertJsonCount(1);
        }
        $this->getJson('/interview-sessions')->assertOk()->assertJsonCount(1);
    }

    public function test_a_candidate_only_reaches_their_own_interview_simulation(): void
    {
        $awa = $this->user('USER', 'awa@example.ci');
        $mine = $this->simulation('Awa@Example.ci');
        $other = $this->simulation('koffi@example.ci');

        $this->actingAs($awa, 'api');
        $this->getJson("/interview-sessions/{$mine->id}")->assertOk()->assertJsonPath('id', $mine->id);
        $this->patchJson("/interview-sessions/{$mine->id}", ['score' => 72, 'status' => 'COMPLETED'])->assertOk()->assertJsonPath('status', 'COMPLETED');

        $this->getJson("/interview-sessions/{$other->id}")->assertForbidden();
        $this->patchJson("/interview-sessions/{$other->id}", ['score' => 0])->assertForbidden();
        $this->deleteJson("/interview-sessions/{$other->id}")->assertForbidden();
        $this->assertNull($other->fresh()->score);

        $this->deleteJson("/interview-sessions/{$mine->id}")->assertOk();
        $this->assertSame(1, InterviewSession::count());
    }
}
