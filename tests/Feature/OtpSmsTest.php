<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Le SMS n'est ici qu'un doublon de l'email, pour tous les comptes avec un
 * numéro — pas réservé aux forfaits payants, à la différence des
 * notifications (voir NotificationSmsBoostTest) : un compte n'a pas encore
 * d'abonnement au moment où il vérifie son email ou réinitialise son mot de
 * passe. Voir OtpService::send().
 */
class OtpSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mtarget.login' => 'goriya',
            'services.mtarget.password' => 'test-password',
            'services.mtarget.base_url' => 'https://api.mtarget.fr',
            'services.mtarget.sender' => 'GORIYA',
            'services.mtarget.default_country_code' => '225',
        ]);

        Http::fake([
            'api.mtarget.fr/*' => Http::response([
                'results' => [['msisdn' => '+225700000000', 'smscount' => 1, 'code' => 0, 'reason' => 'ACCEPTED', 'ticket' => 't-1']],
            ]),
        ]);
    }

    public function test_sends_an_otp_by_sms_when_the_user_has_a_phone(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => 'Awa Traoré',
            'email' => 'awa.otp@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
            'phone' => '0700000000',
        ]);

        app(OtpService::class)->send($user);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.mtarget.fr/messages'
            && $request->data()['msisdn'] === '+225700000000');
    }

    public function test_does_not_send_a_sms_when_the_user_has_no_phone(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => 'Jean Kouassi',
            'email' => 'jean.otp@example.ci',
            'password' => 'motdepasse-solide',
            'role' => 'USER',
            'status' => 'ACTIVE',
        ]);

        app(OtpService::class)->send($user);

        Http::assertNothingSent();
    }
}
