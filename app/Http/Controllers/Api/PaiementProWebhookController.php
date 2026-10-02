<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * Récepteur de la notification serveur-à-serveur de Paiement Pro (POST
 * form-urlencoded sur l'URL passée en `notificationURL` à l'initialisation —
 * voir PaiementProService). C'est la SOURCE DE VÉRITÉ du résultat : Paiement
 * Pro n'expose pas d'API de consultation de statut.
 *
 * Pas de guard `auth:api` (l'appelant est Paiement Pro). L'authentification
 * repose aujourd'hui sur : un jeton statique ajouté par nos soins à l'URL
 * (PAIEMENTPRO_NOTIFICATION_TOKEN — jeton GORIYA, pas un paramètre de l'API
 * Paiement Pro), le recoupement de `referenceNumber` avec une Transaction
 * PENDING existante, et le contrôle du montant.
 *
 * TODO intégrité : la documentation Paiement Pro renvoie un `hashcode` dans
 * les données de notification, prévu pour vérifier l'intégrité du message.
 * Il n'est PAS encore vérifié ici (formule de calcul à récupérer auprès de
 * Paiement Pro) — c'est ce contrôle qui devrait remplacer le jeton en query
 * string. Le payload brut est loggé pour permettre ce calage.
 *
 * NOTE : la casse/le nom exact des champs (`referenceNumber` vs `reference`,
 * `responsecode` vs `status`) n'a pas été confirmé sur une livraison réelle —
 * plusieurs clés plausibles sont testées.
 */
#[OA\Tag(name: 'Subscriptions', description: "Plans d'abonnement, souscription et paiement")]
class PaiementProWebhookController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    #[OA\Post(
        path: '/webhooks/paiementpro',
        tags: ['Subscriptions'],
        summary: 'Réception de la notification de paiement Paiement Pro',
        responses: [
            new OA\Response(response: 200, description: 'Notification traitée (toujours 200)'),
        ]
    )]
    public function handle(Request $request)
    {
        $payload = $request->all();
        // Corps JSON envoyé sans en-tête Content-Type: application/json :
        // Laravel ne le parse pas et all() ne voit que la query string.
        $decoded = json_decode((string) $request->getContent(), true);
        if (is_array($decoded)) {
            $payload = [...$decoded, ...$payload];
        }
        // Le jeton GORIYA (lu plus bas dans la query string) ne doit finir ni
        // dans les logs ni dans raw_payload.
        unset($payload['token']);
        Log::info('[paiementpro] notification', $payload);
        if (! app()->environment('testing')) {
            // Trace brute tant que le format réel n'est pas confirmé par
            // Paiement Pro — c'est elle qu'il faut relire si un paiement
            // encaissé n'active rien.
            $query = $request->query();
            if (isset($query['token'])) {
                $query['token'] = '[masqué]';
            }
            Log::info('[paiementpro] notification brute', [
                'method' => $request->method(),
                'contentType' => $request->header('Content-Type'),
                'query' => $query,
                'body' => mb_substr((string) $request->getContent(), 0, 2000),
            ]);
        }

        $expectedToken = config('services.paiementpro.notification_token');
        if ($expectedToken && ! hash_equals((string) $expectedToken, (string) $request->query('token', ''))) {
            Log::warning('[paiementpro] notification rejetée : jeton invalide');

            return response()->json(['received' => true]);
        }

        $reference = $payload['referenceNumber'] ?? $payload['reference'] ?? $payload['reference_number'] ?? null;
        if (! $reference) {
            return response()->json(['received' => true]);
        }

        $transaction = Transaction::query()
            ->where('gateway', 'paiementpro')
            ->where('gateway_transaction_id', $reference)
            ->first();

        if (! $transaction) {
            Log::warning('[paiementpro] notification sans Transaction correspondante', ['reference' => $reference]);

            return response()->json(['received' => true]);
        }

        $code = $payload['responsecode'] ?? $payload['responseCode'] ?? $payload['status'] ?? null;
        $success = in_array((string) $code, ['0', 'SUCCESS', 'success'], true);
        $current = $transaction->status;

        // Déjà confirmée (Paiement Pro notifie deux fois dans la même seconde) :
        // on ne réécrit rien, mais on s'assure que l'effet du paiement est bien
        // appliqué — c'est ce rejeu qui rattrape une activation interrompue.
        if ($current === TransactionStatus::SUCCESS) {
            // (Sauf réinitialisation de quota : non idempotente, jamais rejouée.)
            if ($success && $transaction->purpose !== 'USAGE_RESET') {
                $this->fulfill($transaction, $reference);
            }

            return response()->json(['received' => true]);
        }

        // Un échec déjà enregistré peut être suivi d'un succès : sur la même
        // session, le client retente après un premier essai refusé (solde,
        // délai dépassé). Le succès l'emporte ; l'inverse n'est jamais rejoué.
        $replayable = $current === TransactionStatus::PENDING || ($current === TransactionStatus::FAILED && $success);
        if (! $replayable) {
            return response()->json(['received' => true]);
        }

        $notifiedAmount = $payload['amount'] ?? null;
        if ($notifiedAmount !== null && abs((float) $notifiedAmount - (float) $transaction->amount) > 0.01) {
            Log::warning('[paiementpro] montant notifié != montant attendu', [
                'reference' => $reference,
                'notified' => $notifiedAmount,
                'expected' => $transaction->amount,
            ]);

            return response()->json(['received' => true]);
        }

        $newStatus = $success ? TransactionStatus::SUCCESS : TransactionStatus::FAILED;

        // Changement d'état atomique : des deux notifications simultanées, une
        // seule « gagne » la transition et applique les effets non rejouables
        // (réinitialisation de quota). Le statut est écrit en premier et à
        // part, pour qu'aucune erreur ultérieure (payload, journal d'audit) ne
        // puisse laisser un paiement confirmé en PENDING.
        $claimed = Transaction::query()
            ->whereKey($transaction->getKey())
            ->where('status', $current->value)
            ->update(['status' => $newStatus->value]) === 1;

        $transaction->refresh();

        if ($claimed) {
            try {
                $transaction->update(['raw_payload' => $payload]);
            } catch (\Throwable $e) {
                Log::error('[paiementpro] payload non enregistré', ['reference' => $reference, 'error' => $e->getMessage()]);
            }
        }

        // L'activation ne doit pas dépendre du retour du navigateur (onglet
        // fermé, notification arrivée après les re-tentatives du frontend) :
        // on applique l'effet du paiement ici.
        if ($transaction->status === TransactionStatus::SUCCESS) {
            // Réinitialisation de quota : non idempotente, réservée au gagnant.
            if ($claimed || $transaction->purpose !== 'USAGE_RESET') {
                $this->fulfill($transaction, $reference);
            }
        } elseif ($claimed) {
            Log::warning('[paiementpro] notification non reconnue comme un succès', ['reference' => $reference, 'code' => $code]);
        }

        return response()->json(['received' => true]);
    }

    /**
     * Une erreur est loggée mais ne casse pas le 200 : la notification
     * jumelle, verifyCheckout() et checkoutStatus() restent des filets de
     * rattrapage (fulfillTransaction() est idempotent).
     */
    private function fulfill(Transaction $transaction, string $reference): void
    {
        try {
            $this->subscriptionService->fulfillTransaction($transaction);
        } catch (\Throwable $e) {
            Log::error('[paiementpro] activation échouée après paiement confirmé', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
