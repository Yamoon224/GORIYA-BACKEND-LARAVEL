<?php

namespace Tests\Feature;

use App\Contracts\VideoCallProviderInterface;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Services RH, étape 5 : pipeline de recrutement.
 *
 * Ce qui compte :
 *  - le pipeline part des candidatures reçues, cloisonnées par entreprise ;
 *  - l'étape et le statut vu par le candidat avancent ensemble, et laissent
 *    un historique ;
 *  - un entretien convoque le candidat et reçoit un compte rendu ;
 *  - « Embauché » ne s'obtient qu'en créant la fiche employé.
 *
 * Horloge figée au jeudi 10 septembre 2026, avancée quand l'ordre des
 * événements doit être lisible.
 */
class RecruitmentTest extends TestCase
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

                return ['id' => "room-{$this->rooms}", 'slug' => "entretien-{$this->rooms}", 'name' => $name, 'scheduledAt' => $scheduledAt, 'createdAt' => now()->toIso8601String()];
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

    private function enterprise(string $name = 'Goriya Test SARL'): User
    {
        $company = Company::create([
            'name' => $name,
            'sector' => 'Technologie',
            'status' => 'ACTIVE',
            'partnership_date' => '2026-01-01',
        ]);

        return User::create([
            'name' => "RH {$name}",
            'email' => 'rh-'.$company->id.'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
            'company_id' => $company->id,
        ]);
    }

    private function offer(User $rh, string $title = 'Développeuse Full-Stack', string $status = 'ACTIVE'): JobOffer
    {
        return JobOffer::create(['title' => $title, 'type' => 'CDI', 'company_id' => $rh->company_id, 'status' => $status]);
    }

    private function candidature(JobOffer $offer, string $name = 'Marie Dubois', string $status = 'EN_ATTENTE'): Candidature
    {
        $candidat = User::create([
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::lower(Str::random(6)).'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        return Candidature::create([
            'candidate_name' => $name,
            'candidate_email' => $candidat->email,
            'status' => $status,
            'score' => 80,
            'applied_date' => '2026-09-01',
            'user_id' => $candidat->id,
            'job_offer_id' => $offer->id,
        ]);
    }

    /** @return list<string> */
    private function notificationTitles(Candidature $candidature): array
    {
        return Notification::where('user_id', $candidature->user_id)->pluck('title')->all();
    }

    public function test_the_pipeline_lists_company_candidates_with_their_stage(): void
    {
        $rh = $this->enterprise();
        $offer = $this->offer($rh);
        $this->candidature($offer, 'Marie Dubois');
        $this->candidature($offer, 'Yao Kouassi', 'EN_COURS');
        $this->candidature($offer, 'Awa Traoré', 'APPROUVEE');
        $this->candidature($offer, 'Koffi Bamba', 'REJETEE');
        $this->offer($rh, 'Poste confidentiel', 'DRAFT');

        $concurrent = $this->enterprise('Concurrent SARL');
        $this->candidature($this->offer($concurrent), 'Fatou Diallo');

        $stages = collect($this->actingAs($rh, 'api')->getJson('/recruitment/candidates')->assertOk()->assertJsonCount(4)->json())
            ->pluck('stage', 'candidateName');
        $this->assertSame('NEW', $stages['Marie Dubois']);
        $this->assertSame('SCREENING', $stages['Yao Kouassi']);
        $this->assertSame('OFFER', $stages['Awa Traoré']);
        $this->assertSame('REJECTED', $stages['Koffi Bamba']);

        $this->actingAs($rh, 'api')->getJson('/recruitment/candidates?stage=NEW,OFFER')->assertJsonCount(2);
        $this->actingAs($rh, 'api')
            ->getJson('/recruitment/candidates?search=kouassi')
            ->assertJsonCount(1)
            ->assertJsonPath('0.jobOffer.title', 'Développeuse Full-Stack');
        $this->actingAs($rh, 'api')->getJson('/recruitment/candidates?stage=INCONNU')->assertStatus(400);

        // Les brouillons ne reçoivent pas de candidature : absents du récapitulatif.
        $this->actingAs($rh, 'api')
            ->getJson('/recruitment/job-offers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.candidatesCount', 4)
            ->assertJsonPath('0.stages.NEW', 1)
            ->assertJsonPath('0.stages.OFFER', 1)
            ->assertJsonPath('0.stages.HIRED', 0)
            ->assertJsonPath('0.stages.REJECTED', 1);

        $salarie = User::create([
            'name' => 'Salarié curieux',
            'email' => 'curieux@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'company_id' => $rh->company_id,
        ]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($salarie, 'api')->getJson('/recruitment/candidates')->assertForbidden();
    }

    public function test_moving_a_candidate_updates_the_public_status_and_keeps_the_history(): void
    {
        $rh = $this->enterprise();
        $candidature = $this->candidature($this->offer($rh));
        $move = fn (array $body) => $this->actingAs($rh, 'api')->patchJson("/recruitment/candidates/{$candidature->id}/stage", $body);

        $move(['stage' => 'SCREENING'])->assertOk()->assertJsonPath('stage', 'SCREENING')->assertJsonPath('status', 'EN_COURS');
        $this->assertCount(1, $this->notificationTitles($candidature));

        // Même statut public (« en cours ») : le candidat n'est pas relancé.
        Carbon::setTestNow('2026-09-11 09:00:00');
        $move(['stage' => 'INTERVIEW'])->assertOk()->assertJsonPath('status', 'EN_COURS');
        $this->assertCount(1, $this->notificationTitles($candidature));

        Carbon::setTestNow('2026-09-12 09:00:00');
        $move(['stage' => 'OFFER'])->assertOk()->assertJsonPath('status', 'APPROUVEE');
        $this->assertCount(2, $this->notificationTitles($candidature));

        $move(['stage' => 'HIRED'])->assertStatus(400);

        Carbon::setTestNow('2026-09-13 09:00:00');
        $move(['stage' => 'REJECTED', 'comment' => 'Prétentions salariales hors budget'])
            ->assertOk()
            ->assertJsonPath('stage', 'REJECTED')
            ->assertJsonPath('status', 'REJETEE')
            ->assertJsonPath('rejectionReason', 'Prétentions salariales hors budget')
            ->assertJsonCount(4, 'history')
            ->assertJsonPath('history.0.fromStage', 'NEW')
            ->assertJsonPath('history.0.toStage', 'SCREENING')
            ->assertJsonPath('history.3.toStage', 'REJECTED')
            ->assertJsonPath('history.3.comment', 'Prétentions salariales hors budget')
            ->assertJsonPath('history.3.authorName', $rh->name);

        // Statut changé depuis la page Candidatures : l'étape suit.
        $this->actingAs($rh, 'api')->patchJson("/candidatures/{$candidature->id}", ['status' => 'EN_ATTENTE'])->assertOk();
        $this->actingAs($rh, 'api')
            ->getJson("/recruitment/candidates/{$candidature->id}")
            ->assertOk()
            ->assertJsonPath('stage', 'NEW')
            ->assertJsonPath('rejectionReason', null);
    }

    public function test_an_interview_is_scheduled_notified_and_reported(): void
    {
        $rh = $this->enterprise();
        $offer = $this->offer($rh);
        $candidature = $this->candidature($offer);

        $interview = $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$candidature->id}/interviews", [
                'type' => 'VIDEO',
                'scheduledAt' => '2026-09-15T10:00:00Z',
                'durationMinutes' => 45,
                'interviewers' => 'Awa (RH), Koffi (CTO)',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'SCHEDULED')
            ->assertJsonPath('durationMinutes', 45)
            ->assertJsonPath('callSession.status', 'SCHEDULED')
            ->json();
        $this->assertStringStartsWith('2026-09-15T10:00:00', $interview['scheduledAt']);
        $this->assertStringStartsWith('2026-09-15T10:45:00', $interview['endsAt']);
        $this->assertNotNull($interview['candidateNotifiedAt']);
        $call = $interview['callSession']['id'];

        // La salle GORIYA Meet : l'hôte est le recruteur, le candidat la retrouve dans son espace et la rejoint.
        $this->actingAs($rh, 'api')->getJson('/calls')->assertOk()->assertJsonCount(1)->assertJsonPath('0.isHost', true);
        $candidat = User::find($candidature->user_id);
        $this->app['auth']->forgetGuards();
        $this->actingAs($candidat, 'api')
            ->getJson('/calls')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $call)
            ->assertJsonPath('0.isHost', false)
            ->assertJsonPath('0.hostName', $rh->name)
            ->assertJsonPath('0.title', 'Entretien Développeuse Full-Stack · Goriya Test SARL — Marie Dubois');
        $this->actingAs($candidat, 'api')->postJson("/calls/{$call}/join")->assertOk()->assertJsonPath('identity', $candidat->id);
        $this->actingAs($candidat, 'api')->postJson("/calls/{$call}/end")->assertForbidden();
        $this->app['auth']->forgetGuards();

        // Planifier un entretien fait passer le candidat à l'étape « Entretien ».
        $this->actingAs($rh, 'api')
            ->getJson("/recruitment/candidates/{$candidature->id}")
            ->assertJsonPath('stage', 'INTERVIEW')
            ->assertJsonPath('interviewsCount', 1)
            ->assertJsonPath('nextInterview.id', $interview['id']);
        $this->assertContains('Entretien programmé', $this->notificationTitles($candidature));

        $this->actingAs($rh, 'api')
            ->getJson('/recruitment/interviews?from=2026-09-14&to=2026-09-20')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.candidate.name', 'Marie Dubois')
            ->assertJsonPath('0.candidate.jobOfferTitle', 'Développeuse Full-Stack');
        $this->actingAs($rh, 'api')->getJson('/recruitment/interviews?from=2026-09-16')->assertJsonCount(0);

        $this->actingAs($rh, 'api')
            ->patchJson("/recruitment/interviews/{$interview['id']}", ['scheduledAt' => '2026-09-16T14:00:00Z'])
            ->assertOk();
        $this->assertContains('Entretien déplacé', $this->notificationTitles($candidature));
        $this->assertStringStartsWith('2026-09-16T14:00:00', (string) $this->actingAs($rh, 'api')->getJson("/calls/{$call}")->json('scheduledAt'));

        Carbon::setTestNow('2026-09-16 16:00:00');
        $this->actingAs($rh, 'api')
            ->getJson("/recruitment/candidates/{$candidature->id}")
            ->assertJsonPath('nextInterview', null)
            ->assertJsonPath('feedbackDueCount', 1);

        $this->actingAs($rh, 'api')
            ->patchJson("/recruitment/interviews/{$interview['id']}/outcome", ['status' => 'COMPLETED', 'rating' => 6])
            ->assertStatus(400);
        $this->actingAs($rh, 'api')
            ->patchJson("/recruitment/interviews/{$interview['id']}/outcome", [
                'status' => 'COMPLETED',
                'rating' => 4,
                'recommendation' => 'HIRE',
                'feedback' => 'Solide sur React, à challenger sur Laravel.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('outcomeByName', $rh->name)
            ->assertJsonPath('callSession.status', 'ENDED');
        // Entretien passé : la salle est fermée chez lunion.meet.
        $this->assertSame(['entretien-1'], $this->meet->deleted);

        $this->actingAs($rh, 'api')
            ->getJson("/recruitment/candidates/{$candidature->id}")
            ->assertJsonPath('averageRating', 4)
            ->assertJsonPath('feedbackDueCount', 0)
            ->assertJsonPath('interviews.0.recommendation', 'HIRE');

        // Un entretien passé ne se déplace plus et reste au dossier.
        $this->actingAs($rh, 'api')->patchJson("/recruitment/interviews/{$interview['id']}", ['scheduledAt' => '2026-09-20T10:00:00Z'])->assertStatus(400);
        $this->actingAs($rh, 'api')->deleteJson("/recruitment/interviews/{$interview['id']}")->assertStatus(400);

        // Écarter un candidat annule ses entretiens à venir ; un candidat écarté n'en reçoit plus.
        $autre = $this->candidature($offer, 'Yao Kouassi');
        $prevu = $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$autre->id}/interviews", ['type' => 'VIDEO', 'scheduledAt' => '2026-09-21T09:00:00Z'])
            ->assertCreated()
            ->json('id');
        $this->actingAs($rh, 'api')->patchJson("/recruitment/candidates/{$autre->id}/stage", ['stage' => 'REJECTED'])->assertOk();
        $this->actingAs($rh, 'api')
            ->getJson("/recruitment/candidates/{$autre->id}")
            ->assertJsonPath('interviews.0.id', $prevu)
            ->assertJsonPath('interviews.0.status', 'CANCELLED')
            ->assertJsonPath('interviews.0.callSession.status', 'ENDED');
        $this->assertSame(['entretien-1', 'entretien-2'], $this->meet->deleted);
        $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$autre->id}/interviews", ['type' => 'PHONE', 'scheduledAt' => '2026-09-22T09:00:00Z'])
            ->assertStatus(400);
    }

    public function test_a_cancelled_interview_warns_the_candidate_who_was_invited(): void
    {
        $rh = $this->enterprise();
        $candidature = $this->candidature($this->offer($rh));

        $id = $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$candidature->id}/interviews", ['type' => 'PHONE', 'scheduledAt' => '2026-09-14T11:00:00Z'])
            ->assertCreated()
            ->assertJsonPath('callSession', null)
            ->json('id');

        // Passé en visio, l'entretien reçoit sa salle ; revenu au téléphone, elle est fermée.
        $this->actingAs($rh, 'api')
            ->patchJson("/recruitment/interviews/{$id}", ['type' => 'VIDEO', 'location' => 'ignoré'])
            ->assertOk()
            ->assertJsonPath('callSession.status', 'SCHEDULED')
            ->assertJsonPath('location', null);
        $this->actingAs($rh, 'api')->patchJson("/recruitment/interviews/{$id}", ['type' => 'PHONE'])->assertOk()->assertJsonPath('callSession', null);
        $this->assertSame(['entretien-1'], $this->meet->deleted);

        $this->actingAs($rh, 'api')
            ->patchJson("/recruitment/interviews/{$id}/outcome", ['status' => 'CANCELLED', 'feedback' => 'Poste gelé'])
            ->assertOk()
            ->assertJsonPath('status', 'CANCELLED')
            ->assertJsonPath('rating', null);
        $this->assertContains('Entretien annulé', $this->notificationTitles($candidature));

        $this->actingAs($rh, 'api')->patchJson("/recruitment/interviews/{$id}/outcome", ['status' => 'COMPLETED'])->assertStatus(400);
        $this->actingAs($rh, 'api')->deleteJson("/recruitment/interviews/{$id}")->assertOk();
    }

    public function test_hiring_from_the_pipeline_ends_the_candidate_journey(): void
    {
        $rh = $this->enterprise();
        $candidature = $this->candidature($this->offer($rh), 'Marie Dubois', 'EN_COURS');
        $employee = [
            'candidatureId' => $candidature->id,
            'firstName' => 'Marie',
            'lastName' => 'Dubois',
            'jobTitle' => 'Développeuse Full-Stack',
            'contractType' => 'CDI',
            'hireDate' => '2026-10-01',
            'salary' => 700000,
        ];

        // Il faut d'abord lui avoir fait une offre.
        $this->actingAs($rh, 'api')->postJson('/employees', $employee)->assertStatus(400);
        $this->actingAs($rh, 'api')->patchJson("/recruitment/candidates/{$candidature->id}/stage", ['stage' => 'OFFER'])->assertOk();

        Carbon::setTestNow('2026-09-10 10:00:00');
        $employeeId = $this->actingAs($rh, 'api')->postJson('/employees', $employee)->assertCreated()->json('id');

        $this->actingAs($rh, 'api')
            ->getJson("/recruitment/candidates/{$candidature->id}")
            ->assertJsonPath('stage', 'HIRED')
            ->assertJsonPath('employeeId', $employeeId)
            ->assertJsonPath('history.1.fromStage', 'OFFER')
            ->assertJsonPath('history.1.toStage', 'HIRED');
        $this->actingAs($rh, 'api')->patchJson("/recruitment/candidates/{$candidature->id}/stage", ['stage' => 'REJECTED'])->assertStatus(400);
        $this->actingAs($rh, 'api')->getJson('/recruitment/job-offers')->assertJsonPath('0.stages.HIRED', 1);

        // Fiche employé supprimée : le candidat revient à l'étape « Offre ».
        $this->actingAs($rh, 'api')->deleteJson("/employees/{$employeeId}")->assertOk();
        $this->actingAs($rh, 'api')->getJson("/recruitment/candidates/{$candidature->id}")->assertJsonPath('stage', 'OFFER');
    }

    public function test_recruiter_notes_stay_within_the_company(): void
    {
        $rh = $this->enterprise();
        $candidature = $this->candidature($this->offer($rh));

        $this->actingAs($rh, 'api')->postJson("/recruitment/candidates/{$candidature->id}/notes", ['body' => '  '])->assertStatus(400);
        $note = $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$candidature->id}/notes", ['body' => 'Très bon échange téléphonique.'])
            ->assertCreated()
            ->assertJsonPath('authorName', $rh->name)
            ->json('id');

        $this->actingAs($rh, 'api')
            ->getJson("/recruitment/candidates/{$candidature->id}")
            ->assertJsonPath('notesCount', 1)
            ->assertJsonPath('notes.0.body', 'Très bon échange téléphonique.');

        $concurrent = $this->enterprise('Concurrent SARL');
        $this->app['auth']->forgetGuards();
        $this->actingAs($concurrent, 'api')->getJson("/recruitment/candidates/{$candidature->id}")->assertNotFound();
        $this->actingAs($concurrent, 'api')->patchJson("/recruitment/candidates/{$candidature->id}/stage", ['stage' => 'OFFER'])->assertNotFound();
        $this->actingAs($concurrent, 'api')
            ->postJson("/recruitment/candidates/{$candidature->id}/interviews", ['type' => 'PHONE', 'scheduledAt' => '2026-09-14T11:00:00Z'])
            ->assertNotFound();
        $this->actingAs($concurrent, 'api')->deleteJson("/recruitment/notes/{$note}")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($rh, 'api')->deleteJson("/recruitment/notes/{$note}")->assertOk();
        $this->actingAs($rh, 'api')->getJson("/recruitment/candidates/{$candidature->id}")->assertJsonPath('notesCount', 0);
    }
}
