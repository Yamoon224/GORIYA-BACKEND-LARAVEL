<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Historique des factures » (entreprise/app/(protected)/parametres) : liste
 * les tentatives de paiement de l'utilisateur connecté, la plus récente
 * d'abord, et refuse l'accès à l'historique de facturation d'un autre compte.
 */
class SubscriptionTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'rh@entreprise.ci'): User
    {
        return User::create([
            'name' => 'Responsable RH',
            'email' => $email,
            'password' => 'motdepasse-solide',
            'role' => 'ENTREPRISE',
            'status' => 'ACTIVE',
        ]);
    }

    private function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Business',
            'price' => 15000,
            'billing_period' => 'MONTHLY',
            'user_type' => 'ENTREPRISE',
            'is_active' => true,
            'features' => [],
        ]);
    }

    public function test_lists_the_authenticated_users_transactions_most_recent_first(): void
    {
        $user = $this->user();
        $plan = $this->plan();

        $old = Transaction::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'gateway' => 'kkiapay',
            'gateway_transaction_id' => 'txn-old',
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => 'SUCCESS',
        ]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $recent = Transaction::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'gateway' => 'paiementpro',
            'gateway_transaction_id' => 'txn-recent',
            'amount' => 15000,
            'currency' => 'XOF',
            'status' => 'FAILED',
        ]);

        $response = $this->actingAs($user, 'api')
            ->getJson("/subscriptions/me/{$user->id}/transactions")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame($recent->id, $response->json('data.0.id'));
        $this->assertSame('FAILED', $response->json('data.0.status'));
        $this->assertSame('Business', $response->json('data.0.planName'));
        $this->assertSame($old->id, $response->json('data.1.id'));
    }

    public function test_a_user_cannot_read_another_accounts_billing_history(): void
    {
        $user = $this->user('rh@entreprise.ci');
        $other = $this->user('autre@entreprise.ci');

        $response = $this->actingAs($user, 'api')
            ->getJson("/subscriptions/me/{$other->id}/transactions");

        $response->assertStatus(403);
    }
}
