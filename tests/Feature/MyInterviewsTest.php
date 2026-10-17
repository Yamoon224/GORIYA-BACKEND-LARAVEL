<?php

namespace Tests\Feature;

use App\Contracts\VideoCallProviderInterface;
use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Espace candidat : ses propres entretiens (GORIYA Meet ou présentiel),
 * tous recruteurs confondus, sans aucune donnée RH interne.
 */
class MyInterviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 09:00:00');

        $this->app->instance(VideoCallProviderInterface::class, new class implements VideoCallProviderInterface
        {
            private int $rooms = 0;

            public function createRoom(string $name, ?string $scheduledAt = null, ?string $description = null): array
            {
                $this->rooms++;

                return ['id' => "room-{$this->rooms}", 'slug' => "entretien-{$this->rooms}", 'name' => $name, 'scheduledAt' => $scheduledAt, 'createdAt' => now()->toIso8601String()];
            }

            public function deleteRoom(string $slug): void {}

            public function issueToken(string $slug, string $identity, ?string $name = null, array $grants = [], int $ttlSeconds = 21600): array
            {
                return ['token' => "jeton-{$identity}", 'url' => 'wss://meet.test', 'room' => $slug, 'identity' => $identity, 'expiresAt' => now()->addHours(6)->toIso8601String()];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enterprise(string $name): User
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

    private function candidature(JobOffer $offer, User $candidat, string $name = 'Marie Dubois'): Candidature
    {
        return Candidature::create([
            'candidate_name' => $name,
            'candidate_email' => $candidat->email,
            'status' => 'EN_ATTENTE',
            'score' => 80,
            'applied_date' => '2026-09-01',
            'user_id' => $candidat->id,
            'job_offer_id' => $offer->id,
        ]);
    }

    private function candidate(string $name = 'Marie Dubois'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::lower(Str::random(6)).'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_the_candidate_only_sees_their_own_interviews_with_company_and_offer_context(): void
    {
        $rh = $this->enterprise('Goriya Test SARL');
        $offer = JobOffer::create(['title' => 'Développeuse Full-Stack', 'type' => 'CDI', 'company_id' => $rh->company_id, 'status' => 'ACTIVE']);

        $candidat = $this->candidate('Marie Dubois');
        $candidature = $this->candidature($offer, $candidat);

        $autreCandidat = $this->candidate('Yao Kouassi');
        $autreCandidature = $this->candidature($offer, $autreCandidat, 'Yao Kouassi');

        $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$candidature->id}/interviews", [
                'type' => 'VIDEO',
                'scheduledAt' => '2026-09-15T10:00:00Z',
                'durationMinutes' => 45,
            ])
            ->assertCreated();

        $this->actingAs($rh, 'api')
            ->postJson("/recruitment/candidates/{$autreCandidature->id}/interviews", [
                'type' => 'ONSITE',
                'scheduledAt' => '2026-09-16T09:00:00Z',
                'location' => '12 rue du Commerce, Abidjan',
            ])
            ->assertCreated();

        $this->app['auth']->forgetGuards();

        $response = $this->actingAs($candidat, 'api')
            ->getJson('/me/interviews')
            ->assertOk()
            ->assertJsonCount(1);

        $response
            ->assertJsonPath('0.type', 'VIDEO')
            ->assertJsonPath('0.companyName', 'Goriya Test SARL')
            ->assertJsonPath('0.jobOfferTitle', 'Développeuse Full-Stack')
            ->assertJsonPath('0.callSession.status', 'SCHEDULED')
            ->assertJsonPath('0.location', null);
        $this->assertArrayNotHasKey('interviewers', $response->json('0'));
        $this->assertArrayNotHasKey('feedback', $response->json('0'));

        $this->app['auth']->forgetGuards();
        $this->actingAs($autreCandidat, 'api')
            ->getJson('/me/interviews')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'ONSITE')
            ->assertJsonPath('0.location', '12 rue du Commerce, Abidjan')
            ->assertJsonPath('0.callSession', null);
    }

    public function test_a_candidate_with_no_interview_gets_an_empty_list(): void
    {
        $this->actingAs($this->candidate(), 'api')->getJson('/me/interviews')->assertOk()->assertJsonCount(0);
    }
}
