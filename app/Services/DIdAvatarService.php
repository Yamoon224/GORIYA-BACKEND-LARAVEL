<?php

namespace App\Services;

use App\Contracts\AvatarGenerationServiceInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client fin pour l'API "Talks" de D-ID (https://docs.d-id.com) — génère
 * une vidéo d'avatar parlant à partir d'une photo et d'un script texte (D-ID
 * gère la synthèse vocale et le lip-sync en interne, pas besoin d'un audio
 * pré-enregistré).
 *
 * NOTE : le format d'authentification (Basic avec la clé en tant que
 * "username") est celui documenté par D-ID au moment de l'écriture — à
 * revérifier contre un compte réel avant mise en production, aucune clé
 * n'étant configurée dans cet environnement de développement.
 */
class DIdAvatarService implements AvatarGenerationServiceInterface
{
    public function createTalk(string $sourcePhotoUrl, string $script): string
    {
        $response = $this->client()->post('/talks', [
            'source_url' => $sourcePhotoUrl,
            'script' => [
                'type' => 'text',
                'input' => $script,
                'provider' => ['type' => 'microsoft', 'voice_id' => 'fr-FR-DeniseNeural'],
            ],
            'config' => ['fluent' => true],
        ]);

        if ($response->failed()) {
            Log::error('D-ID talk creation failed: '.$response->body());
            abort(502, "Échec de la création de l'avatar animé (fournisseur D-ID)");
        }

        $id = $response->json('id');
        if (! $id) {
            abort(502, 'Réponse D-ID invalide : identifiant de talk manquant');
        }

        return $id;
    }

    public function getTalkStatus(string $talkId): array
    {
        $response = $this->client()->get("/talks/{$talkId}");

        if ($response->failed()) {
            Log::error('D-ID talk status check failed: '.$response->body());

            return ['status' => 'FAILED', 'resultUrl' => null];
        }

        $status = $response->json('status');

        return [
            'status' => match ($status) {
                'done' => 'DONE',
                'error', 'rejected' => 'FAILED',
                default => 'PENDING', // created|started
            },
            'resultUrl' => $response->json('result_url'),
        ];
    }

    private function client(): PendingRequest
    {
        $apiKey = config('services.d_id.api_key');

        if (! $apiKey) {
            abort(500, 'Clé D-ID non configurée (D_ID_API_KEY)');
        }

        return Http::withHeaders([
            'Authorization' => 'Basic '.base64_encode("{$apiKey}:"),
            'Accept' => 'application/json',
        ])->baseUrl(config('services.d_id.base_url'));
    }
}
