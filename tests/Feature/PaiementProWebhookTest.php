<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Notification serveur-à-serveur Paiement Pro — voir
 * PaiementProWebhookController. C'est la SOURCE DE VÉRITÉ du résultat d'un
 * paiement (Paiement Pro n'expose aucune API de consultation de statut), donc
 * la seule pièce du flux qu'on puisse verrouiller sans dépendance externe.
 *
 * Invariant transverse : l'endpoint répond TOUJOURS 200, même quand il rejette
 * la notification — un statut d'erreur déclencherait des rejeux côté Paiement
 * Pro sans rien corriger.
 */
class PaiementProWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/webhooks/paiementpro';

    private function transaction(array $attributes = []): Transaction
    {
        return Transaction::create([
            'user_id' => User::factory()->create()->id,
            'gateway' => 'paiementpro',
            'gateway_transaction_id' => 'REF-123',
            'amount' => 5000,
            'currency' => 'XOF',
            'status' => TransactionStatus::PENDING,
            ...$attributes,
        ]);
    }

    public function test_notification_de_succes_active_la_transaction(): void
    {
        $transaction = $this->transaction();

        $this->post(self::URL, [
            'referenceNumber' => 'REF-123',
            'responsecode' => '0',
            'amount' => '5000',
        ])->assertOk();

        $transaction->refresh();
        $this->assertSame(TransactionStatus::SUCCESS, $transaction->status);
        // Le payload brut est conservé : c'est lui qui permettra de caler la
        // vérification du hashcode une fois la formule obtenue.
        $this->assertSame('0', $transaction->raw_payload['responsecode']);
    }

    public function test_notification_en_echec_marque_la_transaction_failed(): void
    {
        $transaction = $this->transaction();

        $this->post(self::URL, [
            'referenceNumber' => 'REF-123',
            'responsecode' => '1',
            'amount' => '5000',
        ])->assertOk();

        $this->assertSame(TransactionStatus::FAILED, $transaction->refresh()->status);
    }

    /**
     * Les noms/casse exacts des champs n'ont pas encore été confirmés sur une
     * livraison réelle : le contrôleur accepte plusieurs variantes plausibles.
     * Ce test documente celles qui sont couvertes — il devra être resserré sur
     * les noms réels dès le premier callback observé en production.
     *
     * @param  array<string, string>  $payload
     */
    #[DataProvider('variantesDeChamps')]
    public function test_variantes_de_nommage_des_champs(array $payload, TransactionStatus $attendu): void
    {
        $transaction = $this->transaction();

        $this->post(self::URL, $payload)->assertOk();

        $this->assertSame($attendu, $transaction->refresh()->status);
    }

    /**
     * @return array<string, array{array<string, string>, TransactionStatus}>
     */
    public static function variantesDeChamps(): array
    {
        return [
            'reference + status' => [
                ['reference' => 'REF-123', 'status' => 'SUCCESS'], TransactionStatus::SUCCESS,
            ],
            'reference_number + responseCode' => [
                ['reference_number' => 'REF-123', 'responseCode' => '0'], TransactionStatus::SUCCESS,
            ],
            'status minuscule' => [
                ['referenceNumber' => 'REF-123', 'status' => 'success'], TransactionStatus::SUCCESS,
            ],
            'code inconnu => echec' => [
                ['referenceNumber' => 'REF-123', 'responsecode' => 'XX'], TransactionStatus::FAILED,
            ],
            'aucun code => echec' => [
                ['referenceNumber' => 'REF-123'], TransactionStatus::FAILED,
            ],
        ];
    }

    public function test_montant_divergent_est_rejete_et_laisse_la_transaction_pending(): void
    {
        $transaction = $this->transaction();

        $this->post(self::URL, [
            'referenceNumber' => 'REF-123',
            'responsecode' => '0',
            'amount' => '50',
        ])->assertOk();

        $this->assertSame(TransactionStatus::PENDING, $transaction->refresh()->status);
    }

    public function test_montant_absent_ne_bloque_pas_la_validation(): void
    {
        $transaction = $this->transaction();

        $this->post(self::URL, ['referenceNumber' => 'REF-123', 'responsecode' => '0'])->assertOk();

        $this->assertSame(TransactionStatus::SUCCESS, $transaction->refresh()->status);
    }

    public function test_transaction_deja_tranchee_n_est_pas_rejouee(): void
    {
        $transaction = $this->transaction(['status' => TransactionStatus::SUCCESS]);

        $this->post(self::URL, [
            'referenceNumber' => 'REF-123',
            'responsecode' => '1',
            'amount' => '5000',
        ])->assertOk();

        $this->assertSame(TransactionStatus::SUCCESS, $transaction->refresh()->status);
    }

    public function test_reference_inconnue_est_ignoree(): void
    {
        $transaction = $this->transaction();

        $this->post(self::URL, ['referenceNumber' => 'AUTRE-REF', 'responsecode' => '0'])->assertOk();

        $this->assertSame(TransactionStatus::PENDING, $transaction->refresh()->status);
    }

    public function test_transaction_d_un_autre_gateway_n_est_pas_touchee(): void
    {
        $transaction = $this->transaction(['gateway' => 'kkiapay']);

        $this->post(self::URL, ['referenceNumber' => 'REF-123', 'responsecode' => '0'])->assertOk();

        $this->assertSame(TransactionStatus::PENDING, $transaction->refresh()->status);
    }

    public function test_notification_sans_reference_est_ignoree(): void
    {
        $this->post(self::URL, ['responsecode' => '0'])->assertOk();
    }

    public function test_jeton_absent_fait_rejeter_la_notification(): void
    {
        config(['services.paiementpro.notification_token' => 'jeton-secret']);
        $transaction = $this->transaction();

        $this->post(self::URL, ['referenceNumber' => 'REF-123', 'responsecode' => '0'])->assertOk();

        $this->assertSame(TransactionStatus::PENDING, $transaction->refresh()->status);
    }

    public function test_jeton_invalide_fait_rejeter_la_notification(): void
    {
        config(['services.paiementpro.notification_token' => 'jeton-secret']);
        $transaction = $this->transaction();

        $this->post(self::URL.'?token=mauvais', [
            'referenceNumber' => 'REF-123',
            'responsecode' => '0',
        ])->assertOk();

        $this->assertSame(TransactionStatus::PENDING, $transaction->refresh()->status);
    }

    public function test_jeton_valide_laisse_passer_la_notification(): void
    {
        config(['services.paiementpro.notification_token' => 'jeton-secret']);
        $transaction = $this->transaction();

        $this->post(self::URL.'?token=jeton-secret', [
            'referenceNumber' => 'REF-123',
            'responsecode' => '0',
        ])->assertOk();

        $this->assertSame(TransactionStatus::SUCCESS, $transaction->refresh()->status);
    }

    /**
     * Le verbe de livraison (GET vs POST) n'est pas garanti par la
     * documentation : les deux sont routés vers le même handler.
     */
    public function test_la_notification_est_acceptee_en_get(): void
    {
        $transaction = $this->transaction();

        $this->get(self::URL.'?referenceNumber=REF-123&responsecode=0')->assertOk();

        $this->assertSame(TransactionStatus::SUCCESS, $transaction->refresh()->status);
    }

    public function test_l_endpoint_est_public(): void
    {
        $this->post(self::URL, ['referenceNumber' => 'REF-123'])->assertOk();
    }
}
