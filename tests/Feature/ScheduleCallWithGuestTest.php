<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * « Planifier Goriya Meet » depuis une conversation (entreprise/standard
 * app/(protected)/messages) : l'autre participant doit être invité, sinon il
 * ne verrait jamais la session apparaître dans son propre /appels.
 */
class ScheduleCallWithGuestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // La création de la room passe par le fournisseur externe lunion.meet
        // (voir LunionMeetService::createRoom) — on le simule plutôt que
        // d'appeler le vrai service depuis les tests.
        config(['services.lunion_meet.api_key' => 'test-key']);
        Http::fake(['*/sdk/rooms' => Http::response(['slug' => 'test-room-'.uniqid()], 200)]);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Utilisateur '.$email,
            'email' => $email,
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_an_invited_guest_sees_the_session_the_host_scheduled(): void
    {
        $host = $this->user('host@example.ci');
        $guest = $this->user('guest@example.ci');

        $sessionId = $this->actingAs($host, 'api')
            ->postJson('/calls', ['title' => 'Échange RH', 'guestIds' => [$guest->id]])
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($guest, 'api')
            ->getJson('/calls')
            ->assertOk()
            ->assertJsonFragment(['id' => $sessionId]);
    }

    public function test_an_unrelated_user_does_not_see_the_session(): void
    {
        $host = $this->user('host2@example.ci');
        $guest = $this->user('guest2@example.ci');
        $stranger = $this->user('stranger@example.ci');

        $this->actingAs($host, 'api')
            ->postJson('/calls', ['title' => 'Échange RH', 'guestIds' => [$guest->id]])
            ->assertStatus(201);

        $this->actingAs($stranger, 'api')->getJson('/calls')->assertOk()->assertJsonCount(0);
    }
}
