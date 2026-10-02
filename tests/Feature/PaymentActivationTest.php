<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TransactionStatus;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Activation de l'abonnement après paiement : les scénarios qui ont déjà
 * produit l'écran « Activation échouée » alors que le paiement était encaissé.
 * Voir PaiementProWebhookController et SubscriptionService::fulfillTransaction().
 */
class PaymentActivationTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = '/webhooks/paiementpro';

    private const REFERENCE = 'REF-ACTIVATION';

    private function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Standard',
            'price' => 1999,
            'billing_period' => 'MONTHLY',
            'user_type' => 'USER',
            'is_active' => true,
            'features' => [],
        ]);
    }

    private function transaction(array $attributes = []): Transaction
    {
        return Transaction::create([
            'user_id' => User::factory()->create()->id,
            'plan_id' => $this->plan()->id,
            'gateway' => 'paiementpro',
            'gateway_transaction_id' => self::REFERENCE,
            'amount' => 1999,
            'currency' => 'XOF',
            'period_months' => 1,
            'purpose' => 'SUBSCRIPTION',
            'status' => TransactionStatus::PENDING,
            ...$attributes,
        ]);
    }

    private function notify(string $code = '0'): void
    {
        $this->post(self::WEBHOOK, ['referenceNumber' => self::REFERENCE, 'responsecode' => $code, 'amount' => '1999'])->assertOk();
    }

    private function activeSubscriptions(Transaction $transaction): int
    {
        return UserSubscription::where('user_id', $transaction->user_id)->where('status', SubscriptionStatus::ACTIVE)->count();
    }

    public function test_la_notification_active_l_abonnement(): void
    {
        $transaction = $this->transaction();

        $this->notify();

        $this->assertSame(TransactionStatus::SUCCESS, $transaction->refresh()->status);
        $this->assertSame(1, $this->activeSubscriptions($transaction));
    }

    public function test_la_double_notification_ne_cree_qu_un_abonnement(): void
    {
        $transaction = $this->transaction();

        // Paiement Pro notifie deux fois dans la même seconde.
        $this->notify();
        $this->notify();

        $this->assertSame(1, UserSubscription::where('user_id', $transaction->user_id)->count());
    }

    public function test_un_paiement_confirme_mais_non_active_est_rattrape_par_la_seconde_notification(): void
    {
        // Incident du 2026-10-01 : transaction passée en SUCCESS, activation
        // interrompue avant la création de l'abonnement.
        $transaction = $this->transaction(['status' => TransactionStatus::SUCCESS]);
        $this->assertSame(0, $this->activeSubscriptions($transaction));

        $this->notify();

        $this->assertSame(1, $this->activeSubscriptions($transaction));
    }

    public function test_un_succes_apres_un_echec_active_l_abonnement(): void
    {
        $transaction = $this->transaction();

        // Premier essai refusé, puis le client retente sur la même session.
        $this->notify('-1');
        $this->assertSame(TransactionStatus::FAILED, $transaction->refresh()->status);

        $this->notify('0');

        $this->assertSame(TransactionStatus::SUCCESS, $transaction->refresh()->status);
        $this->assertSame(1, $this->activeSubscriptions($transaction));
    }

    public function test_un_echec_ne_degrade_jamais_un_paiement_confirme(): void
    {
        $transaction = $this->transaction();

        $this->notify('0');
        $this->notify('-1');

        $this->assertSame(TransactionStatus::SUCCESS, $transaction->refresh()->status);
        $this->assertSame(1, $this->activeSubscriptions($transaction));
    }

    public function test_le_statut_est_consultable_sans_authentification(): void
    {
        $transaction = $this->transaction();

        $this->getJson('/subscriptions/checkout/status/'.self::REFERENCE)
            ->assertOk()
            ->assertJsonPath('status', 'PENDING');

        $this->notify();

        $this->getJson('/subscriptions/checkout/status/'.self::REFERENCE)
            ->assertOk()
            ->assertJsonPath('status', 'SUCCESS')
            ->assertJsonPath('planName', 'Standard');

        $this->assertSame(1, UserSubscription::where('user_id', $transaction->user_id)->count());
    }

    public function test_la_consultation_du_statut_active_un_paiement_confirme_non_active(): void
    {
        $transaction = $this->transaction(['status' => TransactionStatus::SUCCESS]);

        $this->getJson('/subscriptions/checkout/status/'.self::REFERENCE)->assertJsonPath('status', 'SUCCESS');

        $this->assertSame(1, $this->activeSubscriptions($transaction));
    }

    public function test_une_reference_inconnue_renvoie_unknown(): void
    {
        $this->getJson('/subscriptions/checkout/status/INCONNUE')->assertOk()->assertJsonPath('status', 'UNKNOWN');
    }

    public function test_un_renouvellement_prolonge_l_abonnement_en_cours(): void
    {
        $transaction = $this->transaction();
        $echeance = now()->addDays(10)->startOfSecond();
        UserSubscription::create([
            'user_id' => $transaction->user_id,
            'plan_id' => $transaction->plan_id,
            'status' => SubscriptionStatus::ACTIVE,
            'start_date' => now()->subDays(20),
            'end_date' => $echeance,
            'period_months' => 1,
            'auto_renew' => false,
        ]);

        $this->notify();

        $active = UserSubscription::where('user_id', $transaction->user_id)->where('status', SubscriptionStatus::ACTIVE)->get();
        $this->assertCount(1, $active);
        // Les dix jours restants ne sont pas perdus : un mois s'ajoute à l'échéance.
        $this->assertTrue($active->first()->end_date->equalTo($echeance->copy()->addMonth()));
    }

    public function test_verify_checkout_s_appuie_sur_la_transaction_et_non_sur_l_url(): void
    {
        $transaction = $this->transaction(['status' => TransactionStatus::SUCCESS]);
        $user = User::find($transaction->user_id);

        // userId / planId altérés dans l'URL de retour : l'activation porte
        // quand même sur l'utilisateur et le plan de la transaction.
        $this->actingAs($user, 'api')
            ->getJson('/subscriptions/checkout/verify/'.self::REFERENCE.'?userId=faux&planId=faux&gateway=paiementpro')
            ->assertOk();

        $this->assertSame(1, $this->activeSubscriptions($transaction));
    }
}
