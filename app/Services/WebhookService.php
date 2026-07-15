<?php

namespace App\Services;

use App\Jobs\SendWebhookJob;
use App\Models\ApiClient;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Abonnements webhook des intégrations API B2B — voir SendWebhookJob pour
 * l'envoi effectif (asynchrone, signé HMAC).
 */
class WebhookService
{
    public function listForClient(ApiClient $client): Collection
    {
        return Webhook::where('api_client_id', $client->id)->orderByDesc('created_at')->get();
    }

    /**
     * @param  array<int, string>  $events
     */
    public function create(ApiClient $client, string $url, array $events): Webhook
    {
        return Webhook::create([
            'api_client_id' => $client->id,
            'url' => $url,
            'events' => $events,
            'secret' => Str::random(32),
            'is_active' => true,
        ]);
    }

    public function delete(Webhook $webhook): void
    {
        $webhook->delete();
    }

    /**
     * Dispatch best-effort vers tous les webhooks actifs des ApiClient
     * actifs d'une entreprise abonnés à cet événement. Ne bloque jamais
     * l'appelant (voir SendWebhookJob::handle(), même philosophie que
     * NotificationService::pushToUser()).
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $companyId, string $event, array $payload): void
    {
        $webhooks = Webhook::whereHas('apiClient', fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))
            ->where('is_active', true)
            ->whereJsonContains('events', $event)
            ->get();

        foreach ($webhooks as $webhook) {
            SendWebhookJob::dispatch($webhook->id, $event, $payload);
        }
    }

    public function sign(string $secret, string $payload): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
}
