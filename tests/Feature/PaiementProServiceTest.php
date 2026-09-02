<?php

namespace Tests\Feature;

use App\Services\PaiementProService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Initialisation de session Paiement Pro (PaiementProService). Le corps envoyé
 * a été validé contre l'API réelle : la casse des champs est significative
 * (`customerLastname` sans L majuscule, `notificationURL`/`returnURL` en
 * capitales) — d'où les assertions champ par champ.
 */
class PaiementProServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.goriya.test',
            'services.paiementpro.merchant_id' => 'PP-F4045',
            'services.paiementpro.base_url' => 'https://www.paiementpro.net',
            'services.paiementpro.currency_code' => '952',
            'services.paiementpro.channel' => null,
            'services.paiementpro.default_phone' => null,
            'services.paiementpro.notification_token' => null,
        ]);
    }

    private function fakeInitSuccess(): void
    {
        Http::fake([
            '*curl-init.php' => Http::response([
                'message' => 'Initialisation effectuée avec succès',
                'success' => true,
                'url' => 'https://paiementpro.net/webservice/onlinepayment/processing_v2.php?sessionid=abc123',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{sessionId: string, checkoutUrl: string}
     */
    private function createSession(array $overrides = []): array
    {
        return app(PaiementProService::class)->createCheckoutSession([
            'amount' => 5000,
            'currency' => 'XOF',
            'successUrl' => 'https://goriya.test/auth/payment-success?userId=u1&planId=p1',
            'errorUrl' => 'https://goriya.test/auth/payment-error',
            'clientReference' => 'REF-123',
            'customerEmail' => 'client@goriya.test',
            'customerFirstName' => 'Awa',
            'customerLastName' => 'Kone',
            'customerPhone' => '0700000001',
            'userId' => 'u1',
            'planId' => 'p1',
            ...$overrides,
        ]);
    }

    private function sentBody(): array
    {
        $body = null;
        Http::assertSent(function (Request $request) use (&$body) {
            $body = $request->data();

            return true;
        });

        return $body ?? [];
    }

    public function test_la_session_est_identifiee_par_notre_reference_pas_par_le_sessionid(): void
    {
        $this->fakeInitSuccess();

        $session = $this->createSession();

        // sessionId == notre referenceNumber : c'est lui qui revient dans la
        // notification serveur-à-serveur, pas le sessionid éphémère de l'URL.
        $this->assertSame('REF-123', $session['sessionId']);
        $this->assertStringContainsString('processing_v2.php', $session['checkoutUrl']);
    }

    public function test_le_corps_envoye_respecte_la_casse_attendue_par_l_api(): void
    {
        $this->fakeInitSuccess();

        $this->createSession();

        $this->assertSame([
            'merchantId' => 'PP-F4045',
            'amount' => 5000,
            'countryCurrencyCode' => '952',
            'referenceNumber' => 'REF-123',
            'customerEmail' => 'client@goriya.test',
            'customerFirstName' => 'Awa',
            'customerLastname' => 'Kone',
            'customerPhoneNumber' => '0700000001',
        ], collect($this->sentBody())->only([
            'merchantId', 'amount', 'countryCurrencyCode', 'referenceNumber',
            'customerEmail', 'customerFirstName', 'customerLastname', 'customerPhoneNumber',
        ])->all());
    }

    /**
     * RÉGRESSION : la notificationURL a longtemps pointé sur /api/webhooks/...
     * alors que bootstrap/app.php déclare apiPrefix: '' — Paiement Pro recevait
     * donc une URL en 404 et aucune transaction ne pouvait être confirmée.
     * Ce test vérifie que le chemin envoyé est bien routé par l'application.
     */
    public function test_la_notification_url_pointe_sur_une_route_existante(): void
    {
        $this->fakeInitSuccess();

        $this->createSession();

        $notificationUrl = $this->sentBody()['notificationURL'];
        $this->assertStringStartsWith('https://api.goriya.test/', $notificationUrl);

        $path = parse_url($notificationUrl, PHP_URL_PATH);
        $this->post($path, ['referenceNumber' => 'peu-importe'])->assertOk();
    }

    public function test_le_jeton_de_notification_est_ajoute_en_query_string(): void
    {
        config(['services.paiementpro.notification_token' => 'jeton secret']);
        $this->fakeInitSuccess();

        $this->createSession();

        // urlencode() encode l'espace en '+' (style formulaire) et non en %20.
        // Sans conséquence : PHP le redécode en espace côté réception, donc le
        // hash_equals du contrôleur retrouve bien le jeton d'origine.
        $this->assertSame(
            'https://api.goriya.test/webhooks/paiementpro?token=jeton+secret',
            $this->sentBody()['notificationURL']
        );
    }

    public function test_la_reference_est_encodee_dans_le_return_url(): void
    {
        $this->fakeInitSuccess();

        $this->createSession();

        // Le successUrl contient déjà des paramètres : la référence doit être
        // ajoutée avec & et non avec un second ?.
        $this->assertSame(
            'https://goriya.test/auth/payment-success?userId=u1&planId=p1&ref=REF-123',
            $this->sentBody()['returnURL']
        );
    }

    public function test_le_return_context_transporte_user_et_plan(): void
    {
        $this->fakeInitSuccess();

        $this->createSession();

        $this->assertSame(
            ['userId' => 'u1', 'planId' => 'p1'],
            json_decode($this->sentBody()['returnContext'], true)
        );
    }

    public function test_le_channel_est_omis_quand_il_n_est_pas_configure(): void
    {
        $this->fakeInitSuccess();

        $this->createSession();

        // Channel vide => page de choix du moyen de paiement hébergée par
        // Paiement Pro. Envoyer un channel vide forcerait un canal invalide.
        $this->assertArrayNotHasKey('channel', $this->sentBody());
    }

    public function test_le_channel_est_transmis_quand_il_est_configure(): void
    {
        config(['services.paiementpro.channel' => 'OMCIV2']);
        $this->fakeInitSuccess();

        $this->createSession();

        $this->assertSame('OMCIV2', $this->sentBody()['channel']);
    }

    public function test_le_telephone_par_defaut_sert_de_repli(): void
    {
        config(['services.paiementpro.default_phone' => '0100000000']);
        $this->fakeInitSuccess();

        $this->createSession(['customerPhone' => null]);

        $this->assertSame('0100000000', $this->sentBody()['customerPhoneNumber']);
    }

    public function test_le_montant_est_arrondi_a_l_entier(): void
    {
        $this->fakeInitSuccess();

        $this->createSession(['amount' => 4999.6]);

        // XOF n'a pas de sous-unité : l'API refuse un montant décimal.
        $this->assertSame(5000, $this->sentBody()['amount']);
    }

    public function test_l_absence_de_merchant_id_echoue_explicitement(): void
    {
        config(['services.paiementpro.merchant_id' => null]);
        Http::fake();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('PAIEMENTPRO_MERCHANT_ID non configuré');

        $this->createSession();

        Http::assertNothingSent();
    }

    public function test_un_refus_de_l_api_remonte_le_message_en_502(): void
    {
        Http::fake([
            '*curl-init.php' => Http::response([
                'success' => false,
                'message' => 'Marchand inconnu',
            ]),
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Marchand inconnu');

        $this->createSession();
    }

    public function test_une_reponse_sans_url_est_traitee_comme_un_echec(): void
    {
        Http::fake([
            '*curl-init.php' => Http::response(['success' => true]),
        ]);

        $this->expectException(HttpException::class);

        $this->createSession();
    }
}
