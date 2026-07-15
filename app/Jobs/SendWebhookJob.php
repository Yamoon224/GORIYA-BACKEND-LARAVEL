<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Envoie un événement webhook signé HMAC (header X-Goriya-Signature) à une
 * intégration ATS/SIRH partenaire — voir WebhookService::dispatch(). Best-
 * effort : un échec ne remonte jamais à l'action qui a déclenché
 * l'événement (candidature mise à jour, évaluation terminée...).
 */
class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $webhookId,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    public function handle(WebhookService $webhookService): void
    {
        $webhook = Webhook::where('is_active', true)->find($this->webhookId);
        if (! $webhook) {
            return;
        }

        $body = json_encode([
            'event' => $this->event,
            'data' => $this->payload,
            'sentAt' => now()->toIso8601String(),
        ]);

        $signature = $webhookService->sign($webhook->secret, $body);

        $response = Http::withBody($body, 'application/json')
            ->withHeaders(['X-Goriya-Signature' => $signature])
            ->timeout(10)
            ->post($webhook->url);

        if ($response->failed()) {
            Log::warning("Webhook {$webhook->id} ({$this->event}) failed: HTTP {$response->status()}");
            $this->fail(new RuntimeException("Webhook delivery failed with status {$response->status()}"));
        }
    }
}
