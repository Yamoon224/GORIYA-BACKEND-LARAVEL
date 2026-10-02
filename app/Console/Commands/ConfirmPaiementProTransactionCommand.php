<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Rattrapage manuel d'un paiement Paiement Pro encaissé mais jamais activé
 * (notification serveur-à-serveur non reçue ou non reconnue — voir
 * PaiementProWebhookController). Paiement Pro n'expose pas d'API de statut :
 * la confirmation se fait donc À LA MAIN, après avoir vérifié le paiement dans
 * l'espace marchand. Sans argument, liste les transactions en attente.
 */
class ConfirmPaiementProTransactionCommand extends Command
{
    protected $signature = 'paiementpro:confirm {reference? : gateway_transaction_id de la transaction à activer}';

    protected $description = 'Liste les paiements Paiement Pro non activés, ou en active un vérifié dans l\'espace marchand';

    public function handle(SubscriptionService $subscriptions): int
    {
        $reference = $this->argument('reference');

        if (! $reference) {
            $rows = Transaction::query()
                ->where('gateway', 'paiementpro')
                ->where('status', '!=', TransactionStatus::SUCCESS->value)
                ->orderByDesc('created_at')
                ->limit(30)
                ->get()
                ->map(fn (Transaction $t) => [$t->gateway_transaction_id, $t->status->value, $t->amount, $t->purpose, $t->created_at]);

            $this->table(['Référence', 'Statut', 'Montant', 'Objet', 'Créée le'], $rows);

            return self::SUCCESS;
        }

        $transaction = Transaction::query()
            ->where('gateway', 'paiementpro')
            ->where('gateway_transaction_id', $reference)
            ->first();

        if (! $transaction) {
            $this->error("Aucune transaction Paiement Pro pour la référence {$reference}.");

            return self::FAILURE;
        }

        $this->line("Transaction {$reference} — statut {$transaction->status->value}, {$transaction->amount} {$transaction->currency}, utilisateur {$transaction->user_id}.");

        if (! $this->confirm("Le paiement est-il bien encaissé dans l'espace marchand Paiement Pro ?")) {
            return self::FAILURE;
        }

        $transaction->update(['status' => TransactionStatus::SUCCESS]);
        $subscriptions->fulfillTransaction($transaction);

        $this->info('Paiement confirmé et activé.');

        return self::SUCCESS;
    }
}
