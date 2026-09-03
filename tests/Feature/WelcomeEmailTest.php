<?php

namespace Tests\Feature;

use App\Mail\WelcomeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email de bienvenue : il part une fois l'inscription réellement aboutie
 * (email vérifié), jamais à la simple création du compte — qui, elle, ne
 * permet pas encore de se connecter — et jamais deux fois.
 */
class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Awa Koné',
            'email' => 'awa@example.com',
            'password' => 'mot-de-passe',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ], $attributes));
    }

    private function codeFor(User $user): string
    {
        return OtpCode::where('user_id', $user->id)
            ->where('purpose', OtpService::PURPOSE_EMAIL_VERIFICATION)
            ->orderByDesc('created_at')
            ->firstOrFail()
            ->code;
    }

    public function test_signup_only_sends_the_verification_code(): void
    {
        Mail::fake();

        $this->postJson('/users', [
            'name' => 'Awa Koné',
            'email' => 'awa@example.com',
            'password' => 'mot-de-passe',
        ])->assertCreated();

        Mail::assertNotSent(WelcomeMail::class);
    }

    public function test_it_emails_a_welcome_message_once_the_email_is_verified(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        app(OtpService::class)->send($user);

        $this->postJson('/auth/otp/verify', [
            'email' => 'awa@example.com',
            'code' => $this->codeFor($user),
        ])->assertOk();

        Mail::assertSent(WelcomeMail::class, fn (WelcomeMail $mail) => $mail->hasTo('awa@example.com')
            && $mail->user->is($user));
    }

    public function test_it_does_not_send_the_welcome_message_twice(): void
    {
        Mail::fake();
        // `email_verified_at` n'est pas dans $fillable : forceFill, sinon
        // User::create l'ignore silencieusement et le compte reste à vérifier.
        $user = $this->makeUser();
        $user->forceFill(['email_verified_at' => now()])->save();
        app(OtpService::class)->send($user);

        $this->postJson('/auth/otp/verify', [
            'email' => 'awa@example.com',
            'code' => $this->codeFor($user),
        ])->assertOk();

        Mail::assertNotSent(WelcomeMail::class);
    }

    public function test_a_password_reset_does_not_trigger_a_welcome_message(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        app(OtpService::class)->send($user, OtpService::PURPOSE_PASSWORD_RESET);

        $code = OtpCode::where('user_id', $user->id)
            ->where('purpose', OtpService::PURPOSE_PASSWORD_RESET)
            ->orderByDesc('created_at')
            ->firstOrFail()
            ->code;

        app(OtpService::class)->verify('awa@example.com', $code, OtpService::PURPOSE_PASSWORD_RESET);

        Mail::assertNotSent(WelcomeMail::class);
    }
}
