<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Connexion / inscription LinkedIn (POST /auth/linkedin). Le point sensible
 * est que le navigateur n'envoie qu'un `code` : tout le profil vient de
 * l'échange serveur-à-serveur, jamais du client.
 */
class LinkedInAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://goriya.test/auth/linkedin/callback';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.linkedin.client_id' => 'li-client',
            'services.linkedin.client_secret' => 'li-secret',
            'services.linkedin.redirect_uris' => [self::CALLBACK],
        ]);
    }

    private function fakeLinkedIn(array $userinfo = []): void
    {
        Http::fake([
            'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'li-access-token']),
            'https://api.linkedin.com/v2/userinfo' => Http::response(array_merge([
                'sub' => 'li-sub-1',
                'email' => 'Candidat@Example.com',
                'email_verified' => true,
                'name' => 'Awa Koné',
                'picture' => 'https://media.licdn.com/awa.jpg',
            ], $userinfo)),
        ]);
    }

    public function test_it_creates_a_verified_account_on_first_linkedin_sign_in(): void
    {
        $this->fakeLinkedIn();

        $response = $this->postJson('/auth/linkedin', [
            'code' => 'auth-code',
            'redirectUri' => self::CALLBACK,
        ]);

        $response->assertOk()
            ->assertJsonPath('isNewUser', true)
            ->assertJsonPath('user.email', 'candidat@example.com');

        $user = User::where('email', 'candidat@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Awa Koné', $user->name);

        Http::assertSent(fn ($request) => $request->url() === 'https://www.linkedin.com/oauth/v2/accessToken'
            && $request['code'] === 'auth-code'
            && $request['redirect_uri'] === self::CALLBACK
            && $request['client_secret'] === 'li-secret');
    }

    public function test_it_logs_in_an_existing_account_without_creating_a_duplicate(): void
    {
        $existing = User::factory()->create(['email' => 'candidat@example.com']);
        $this->fakeLinkedIn();

        $this->postJson('/auth/linkedin', [
            'code' => 'auth-code',
            'redirectUri' => self::CALLBACK,
        ])->assertOk()
            ->assertJsonPath('isNewUser', false)
            ->assertJsonPath('user.id', $existing->id);

        $this->assertSame(1, User::where('email', 'candidat@example.com')->count());
    }

    public function test_it_rejects_a_redirect_uri_outside_the_allowlist(): void
    {
        $this->fakeLinkedIn();

        $this->postJson('/auth/linkedin', [
            'code' => 'auth-code',
            'redirectUri' => 'https://attaquant.test/auth/linkedin/callback',
        ])->assertStatus(400);

        Http::assertNothingSent();
    }

    public function test_it_refuses_signup_when_allow_signup_is_false(): void
    {
        $this->fakeLinkedIn();

        $this->postJson('/auth/linkedin', [
            'code' => 'auth-code',
            'redirectUri' => self::CALLBACK,
            'allowSignup' => false,
        ])->assertStatus(404);

        $this->assertSame(0, User::where('email', 'candidat@example.com')->count());
    }

    public function test_it_refuses_an_unverified_linkedin_email(): void
    {
        $this->fakeLinkedIn(['email_verified' => false]);

        $this->postJson('/auth/linkedin', [
            'code' => 'auth-code',
            'redirectUri' => self::CALLBACK,
        ])->assertStatus(401);
    }
}
