<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Réinitialisation de mot de passe (POST /auth/password/forgot puis
 * /auth/password/reset). Deux points sensibles : la réponse de /forgot ne
 * doit rien révéler sur l'existence du compte, et un code PASSWORD_RESET ne
 * doit pas être utilisable ailleurs que sur /reset.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Awa Koné',
            'email' => 'awa@example.com',
            'password' => 'ancien-mdp',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function latestCodeFor(User $user): string
    {
        return OtpCode::where('user_id', $user->id)
            ->where('purpose', OtpService::PURPOSE_PASSWORD_RESET)
            ->orderByDesc('created_at')
            ->firstOrFail()
            ->code;
    }

    public function test_it_emails_a_password_reset_code(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->postJson('/auth/password/forgot', ['email' => 'awa@example.com'])->assertOk();

        Mail::assertSent(OtpMail::class, fn (OtpMail $mail) => $mail->hasTo('awa@example.com')
            && $mail->purpose === OtpService::PURPOSE_PASSWORD_RESET);

        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => OtpService::PURPOSE_PASSWORD_RESET,
        ]);
    }

    public function test_it_answers_identically_for_an_unknown_address(): void
    {
        Mail::fake();
        $this->makeUser();

        $known = $this->postJson('/auth/password/forgot', ['email' => 'awa@example.com']);
        $unknown = $this->postJson('/auth/password/forgot', ['email' => 'personne@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
        Mail::assertSentCount(1);
    }

    public function test_it_does_not_send_a_code_to_a_blocked_account(): void
    {
        Mail::fake();
        $this->makeUser(['status' => 'INACTIVE']);

        $this->postJson('/auth/password/forgot', ['email' => 'awa@example.com'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_it_sets_the_new_password_and_consumes_the_code(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->postJson('/auth/password/forgot', ['email' => 'awa@example.com'])->assertOk();
        $code = $this->latestCodeFor($user);

        $this->postJson('/auth/password/reset', [
            'email' => 'awa@example.com',
            'code' => $code,
            'password' => 'nouveau-mdp',
        ])->assertOk();

        $this->assertTrue(Hash::check('nouveau-mdp', $user->fresh()->password));

        // Le code est consommé : il ne permet pas un second changement.
        $this->postJson('/auth/password/reset', [
            'email' => 'awa@example.com',
            'code' => $code,
            'password' => 'encore-un-autre',
        ])->assertStatus(401);

        $this->postJson('/auth/login', [
            'email' => 'awa@example.com',
            'password' => 'nouveau-mdp',
        ])->assertOk();
    }

    public function test_it_verifies_the_email_of_an_unverified_account_on_reset(): void
    {
        Mail::fake();
        $user = $this->makeUser(['email_verified_at' => null]);

        $this->postJson('/auth/password/forgot', ['email' => 'awa@example.com'])->assertOk();

        $this->postJson('/auth/password/reset', [
            'email' => 'awa@example.com',
            'code' => $this->latestCodeFor($user),
            'password' => 'nouveau-mdp',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_it_rejects_a_wrong_code_and_a_too_short_password(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $this->postJson('/auth/password/forgot', ['email' => 'awa@example.com'])->assertOk();

        $this->postJson('/auth/password/reset', [
            'email' => 'awa@example.com',
            'code' => '000000',
            'password' => 'nouveau-mdp',
        ])->assertStatus(401);

        $this->postJson('/auth/password/reset', [
            'email' => 'awa@example.com',
            'code' => $this->latestCodeFor($user),
            // 400 et non 422 : les ValidationException sont normalisées au
            // format NestJS par bootstrap/app.php.
            'password' => '123',
        ])->assertStatus(400);

        $this->assertTrue(Hash::check('ancien-mdp', $user->fresh()->password));
    }

    public function test_a_password_reset_code_cannot_be_traded_for_a_session(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $this->postJson('/auth/password/forgot', ['email' => 'awa@example.com'])->assertOk();

        $this->postJson('/auth/otp/verify', [
            'email' => 'awa@example.com',
            'code' => $this->latestCodeFor($user),
            'purpose' => OtpService::PURPOSE_PASSWORD_RESET,
        ])->assertStatus(400);
    }
}
