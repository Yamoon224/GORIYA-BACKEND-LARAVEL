<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Mirroir de backend/src/wave/wave.service.ts — client HTTP fin pour la
 * passerelle de paiement mobile money Wave. Pas d'entité DB propre.
 */
class WaveService implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.wave.com';

    /**
     * @param  array{amount: string, currency: string, successUrl: string, errorUrl: string, clientReference?: string}  $params
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $params): array
    {
        $body = [
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'success_url' => $params['successUrl'],
            'error_url' => $params['errorUrl'],
        ];

        if (! empty($params['clientReference'])) {
            $body['client_reference'] = $params['clientReference'];
        }

        return $this->request('post', '/v1/checkout/sessions', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCheckoutSession(string $sessionId): array
    {
        return $this->request('get', "/v1/checkout/sessions/{$sessionId}");
    }

    private function apiKey(): string
    {
        $key = config('services.wave.key');

        if (! $key) {
            abort(500, 'WAVE_API_KEY non configurée');
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $request = Http::withToken($this->apiKey())->acceptJson();

        /** @var Response $response */
        $response = $body !== null
            ? $request->{$method}(self::BASE_URL.$path, $body)
            : $request->{$method}(self::BASE_URL.$path);

        if ($response->failed()) {
            abort(502, $response->json('message') ?? "Wave API error {$response->status()}");
        }

        return $response->json() ?? [];
    }
}
