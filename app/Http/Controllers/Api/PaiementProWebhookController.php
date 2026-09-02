<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
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
        Log::info('[paiementpro] notification', $payload);

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

        // Une transaction déjà tranchée n'est pas rejouée (idempotence).
        if ($transaction->status !== TransactionStatus::PENDING) {
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

        $code = $payload['responsecode'] ?? $payload['responseCode'] ?? $payload['status'] ?? null;
        $success = in_array((string) $code, ['0', 'SUCCESS', 'success'], true);

        $transaction->update([
            'status' => $success ? TransactionStatus::SUCCESS : TransactionStatus::FAILED,
            'raw_payload' => $payload,
        ]);

        return response()->json(['received' => true]);
    }
}
