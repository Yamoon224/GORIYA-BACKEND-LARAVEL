<?php

namespace Tests\Feature;

use App\Contracts\VideoCallProviderInterface;
use App\Mail\InterviewInvitationMail;
use App\Models\CallSession;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GORIYA Meet : une session dont l'heure est passée ne reste ni « Planifié »
 * ni « En cours », et un entretien planifié convoque le candidat par email
 * quel que soit son forfait.
 *
 * Horloge figée au jeudi 10 septembre 2026, 09h00.
 */
class CallSessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    /** Faux lunion.meet : salles numérotées, suppressions retenues. */
    private object $meet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 09:00:00');

        $this->meet = new class implements VideoCallProviderInterface
        {
            /** @var list<string> */
            public array $deleted = [];

            private int $rooms = 0;

            public function createRoom(string $name, ?string $scheduledAt = null, ?string $description = null): array
            {
                $this->rooms++;

                return ['id' => "room-{$this->rooms}", 'slug' => "salle-{$this->rooms}"];
            }

            public function deleteRoom(string $slug): void
            {
                $this->deleted[] = $slug;
            }

            public function issueToken(string $slug, string $identity, ?string $name = null, array $grants = [], int $ttlSeconds = 21600): array
            {
                return ['token' => "jeton-{$identity}", 'url' => 'wss://meet.test', 'room' => $slug, 'identity' => $identity, 'expiresAt' => now()->addHours(6)->toIso8601String()];
            }
        };
        $this->app->instance(VideoCallProviderInterface::class, $this->meet);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role = 'USER', ?string $companyId = null): User
    {
        return User::create([
            'name' => 'Utilisateur '.Str::random(5),
            'email' => Str::lower(Str::random(8)).'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => $role,
            'status' => 'ACTIVE',
            'company_id' => $companyId,
        ]);
    }

    private function schedule(User $host, ?string $at): string
    {
        return $this->actingAs($host, 'api')
            ->postJson('/calls', array_filter(['title' => 'Point projet', 'scheduledAt' => $at]))
            ->assertCreated()
            ->json('id');
    }

    public function test_a_scheduled_call_ends_once_its_time_has_passed(): void
    {
        $host = $this->user();
        $id = $this->schedule($host, '2026-09-10T10:00:00Z');

        // Pendant l'appel et sa marge (1 h + 30 min), il reste planifié.
        Carbon::setTestNow('2026-09-10 11:20:00');
        $this->actingAs($host, 'api')->getJson('/calls')->assertOk()->assertJsonPath('0.status', 'SCHEDULED');

        Carbon::setTestNow('2026-09-10 11:31:00');
        $this->actingAs($host, 'api')->getJson('/calls')->assertOk()->assertJsonPath('0.status', 'ENDED');
        $this->assertSame('2026-09-10 11:00:00', CallSession::find($id)->ended_at->toDateTimeString());
        $this->assertSame(['salle-1'], $this->meet->deleted);

        // Passé, il ne se rejoint plus.
        $this->actingAs($host, 'api')->postJson("/calls/{$id}/join")->assertStatus(400);
    }

    public function test_a_call_left_in_progress_ends_after_the_last_connection(): void
    {
        $host = $this->user();
        $id = $this->schedule($host, '2026-09-10T10:00:00Z');

        // Rejoint en retard : l'échéance repart de la dernière connexion.
        Carbon::setTestNow('2026-09-10 11:10:00');
        $this->actingAs($host, 'api')->postJson("/calls/{$id}/join")->assertOk();
        Carbon::setTestNow('2026-09-10 12:00:00');
        $this->actingAs($host, 'api')->getJson("/calls/{$id}")->assertOk()->assertJsonPath('status', 'ACTIVE');

        // Personne ne l'a clôturé : il ne reste pas « En cours » indéfiniment.
        Carbon::setTestNow('2026-09-10 12:41:00');
        $this->actingAs($host, 'api')->getJson("/calls/{$id}")->assertOk()->assertJsonPath('status', 'ENDED');
    }

    public function test_the_scheduled_command_closes_overdue_calls_only(): void
    {
        $host = $this->user();
        $past = $this->schedule($host, '2026-09-10T10:00:00Z');
        $instant = $this->schedule($host, null);
        $future = $this->schedule($host, '2026-09-12T10:00:00Z');

        Carbon::setTestNow('2026-09-11 08:00:00');
        $this->artisan('calls:close-expired')->expectsOutput('2 session(s) terminée(s).')->assertSuccessful();

        $this->assertSame('ENDED', CallSession::find($past)->status->value);
        $this->assertSame('ENDED', CallSession::find($instant)->status->value);
        $this->assertSame('SCHEDULED', CallSession::find($future)->status->value);
    }

    public function test_an_interview_room_follows_the_interview_duration_and_the_candidate_is_emailed(): void
    {
        Mail::fake();

        $company = Company::create(['name' => 'Goriya Test SARL', 'sector' => 'Technologie', 'status' => 'ACTIVE', 'partnership_date' => '2026-01-01']);
        $rh = $this->user('ENTREPRISE', $company->id);
        $candidat = $this->user();
        $offer = JobOffer::create(['title' => 'Développeuse Full-Stack', 'type' => 'CDI', 'company_id' => $company->id, 'status' => 'ACTIVE']);
        $candidature = Candidature::create([
            'candidate_name' => 'Marie Dubois',
            'candidate_email' => 'marie.perso@example.ci',
            'status' => 'EN_ATTENTE',
            'score' => 80,
            'applied_date' => '2026-09-01',
            'user_id' => $candidat->id,
            'job_offer_id' => $offer->id,
        ]);

        $interview = $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$candidature->id}/interviews", [
                'type' => 'VIDEO',
                'scheduledAt' => '2026-09-11T10:00:00Z',
                'durationMinutes' => 120,
            ])
            ->assertCreated()
            ->json();

        // Convocation par email, sans abonnement « notifications prioritaires ».
        Mail::assertSent(InterviewInvitationMail::class, function (InterviewInvitationMail $mail) use ($candidat, $interview) {
            $html = $mail->render();

            return $mail->hasTo($candidat->email)
                && $mail->kind === InterviewInvitationMail::SCHEDULED
                && $mail->envelope()->subject === 'Entretien programmé : Développeuse Full-Stack - Goriya Test SARL'
                && str_contains($html, 'vendredi 11 septembre 2026 à 10h00')
                && str_contains($html, '/appels?rejoindre='.$interview['callSession']['id'])
                && count($mail->attachments()) === 1;
        });

        // Deux heures d'entretien + la marge : la salle reste ouverte jusqu'à 12h30.
        Carbon::setTestNow('2026-09-11 12:20:00');
        $this->actingAs($rh, 'api')->getJson('/recruitment/interviews')->assertOk()->assertJsonPath('0.callSession.status', 'SCHEDULED');
        Carbon::setTestNow('2026-09-11 12:31:00');
        $this->actingAs($rh, 'api')
            ->getJson('/recruitment/interviews')
            ->assertOk()
            // L'entretien attend toujours son compte rendu ; sa salle, elle, est fermée.
            ->assertJsonPath('0.status', 'SCHEDULED')
            ->assertJsonPath('0.callSession.status', 'ENDED');

        // Déplacé puis annulé : le candidat est prévenu par email à chaque fois.
        Carbon::setTestNow('2026-09-10 09:00:00');
        $other = $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$candidature->id}/interviews", ['type' => 'ONSITE', 'scheduledAt' => '2026-09-14T09:00:00Z', 'location' => 'Plateau, Abidjan'])
            ->assertCreated()
            ->json('id');
        $this->actingAs($rh, 'api')->patchJson("/recruitment/interviews/{$other}", ['scheduledAt' => '2026-09-15T09:00:00Z'])->assertOk();
        $this->actingAs($rh, 'api')->patchJson("/recruitment/interviews/{$other}/outcome", ['status' => 'CANCELLED'])->assertOk();

        Mail::assertSent(InterviewInvitationMail::class, fn (InterviewInvitationMail $mail) => $mail->kind === InterviewInvitationMail::RESCHEDULED && $mail->interview->id === $other);
        Mail::assertSent(InterviewInvitationMail::class, fn (InterviewInvitationMail $mail) => $mail->kind === InterviewInvitationMail::CANCELLED && str_contains($mail->render(), 'est annulé'));
        Mail::assertSent(InterviewInvitationMail::class, 4);
    }
}
