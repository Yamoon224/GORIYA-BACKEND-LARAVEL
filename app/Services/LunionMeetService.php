<?php

namespace App\Services;

use App\Contracts\VideoCallProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client fin pour l'API lunion.meet (https://meet.lunion-lab.com/docs) — SFU
 * self-hosted de Lunion-Lab utilisé pour GORIYA Call. Comme DIdAvatarService,
 * un appel externe sans clé configurée échoue explicitement (500) : pas de
 * repli plausible possible pour une visioconférence.
 */
class LunionMeetService implements VideoCallProviderInterface
{
    public function createRoom(string $name, ?string $scheduledAt = null, ?string $description = null): array
    {
        $response = $this->client()->post('/sdk/rooms', array_filter([
            'name' => $name,
            'scheduledAt' => $scheduledAt,
            'description' => $description,
        ], fn ($value) => $value !== null));

        if ($response->failed()) {
            Log::error('lunion.meet room creation failed: '.$response->body());
            abort(502, "Échec de la création de la room d'appel (fournisseur lunion.meet)");
        }

        $slug = $response->json('slug');
        if (! $slug) {
            abort(502, 'Réponse lunion.meet invalide : slug de room manquant');
        }

        return $response->json();
    }

    public function deleteRoom(string $slug): void
    {
        $response = $this->client()->delete("/sdk/rooms/{$slug}");

        if ($response->failed() && $response->status() !== 404) {
            Log::error("lunion.meet room deletion failed ({$slug}): ".$response->body());
            abort(502, "Échec de la suppression de la room d'appel (fournisseur lunion.meet)");
        }
    }

    public function issueToken(
        string $slug,
        string $identity,
        ?string $name = null,
        array $grants = [],
        int $ttlSeconds = 21600
    ): array {
        $response = $this->client()->post("/sdk/rooms/{$slug}/token", array_filter([
            'identity' => $identity,
            'name' => $name,
            'grants' => $grants ?: null,
            'ttlSeconds' => $ttlSeconds,
        ], fn ($value) => $value !== null));

        if ($response->failed()) {
            Log::error("lunion.meet token issuance failed ({$slug}): ".$response->body());
            abort(502, "Échec de l'émission du jeton d'appel (fournisseur lunion.meet)");
        }

        $token = $response->json('token');
        if (! $token) {
            abort(502, 'Réponse lunion.meet invalide : jeton manquant');
        }

        return $response->json();
    }

    private function client(): PendingRequest
    {
        $apiKey = config('services.lunion_meet.api_key');

        if (! $apiKey) {
            abort(500, "Clé lunion.meet non configurée (LUNION_MEET_API_KEY)");
        }

        return Http::withToken($apiKey)
            ->acceptJson()
            ->baseUrl(config('services.lunion_meet.base_url'));
    }
}
