<?php

namespace App\Services;

use App\Contracts\PushNotificationServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoie des push FCM via l'API HTTP v1 (compte de service Google), sans
 * dépendre du SDK kreait/firebase-php — son arbre de dépendances (lcobucci/jwt,
 * guzzlehttp/promises) entre en conflit avec tymon/jwt-auth déjà utilisé pour
 * l'auth API de ce backend. La signature JWT RS256 du compte de service est
 * donc faite à la main via openssl (aucune nouvelle dépendance composer).
 *
 * Sans compte de service configuré, no-op silencieux (avec log) plutôt
 * qu'une erreur — voir PushNotificationServiceInterface.
 */
class FcmPushNotificationService implements PushNotificationServiceInterface
{
    private const TOKEN_CACHE_KEY = 'fcm_access_token';

    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        if ($tokens === []) {
            return;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            Log::info('FCM non configuré (FCM_SERVICE_ACCOUNT_PATH) — push notification ignorée.');

            return;
        }

        $projectId = config('services.fcm.project_id');

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($accessToken)->post(
                    "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                    [
                        'message' => [
                            'token' => $token,
                            'notification' => ['title' => $title, 'body' => $body],
                            'data' => $data,
                        ],
                    ],
                );

                if ($response->failed()) {
                    Log::warning('Échec envoi push FCM: '.$response->body());
                }
            } catch (Throwable $e) {
                Log::warning('Exception envoi push FCM: '.$e->getMessage());
            }
        }
    }

    private function getAccessToken(): ?string
    {
        $path = config('services.fcm.service_account_path');
        if (! $path || ! is_file($path)) {
            return null;
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(50), function () use ($path) {
            $serviceAccount = json_decode((string) file_get_contents($path), true);
            if (! is_array($serviceAccount) || empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
                Log::warning('Fichier de compte de service FCM invalide.');

                return null;
            }

            $jwt = $this->buildSignedJwt($serviceAccount);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->failed()) {
                Log::warning('Échec échange du JWT contre un access token OAuth2: '.$response->body());

                return null;
            }

            return $response->json('access_token');
        });
    }

    /**
     * Flow JWT-bearer standard OAuth2 pour compte de service Google (RFC
     * 7523) — https://developers.google.com/identity/protocols/oauth2/service-account
     *
     * @param  array{client_email: string, private_key: string}  $serviceAccount
     */
    private function buildSignedJwt(array $serviceAccount): string
    {
        $now = time();

        $segments = [
            $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode((string) json_encode([
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        openssl_sign(implode('.', $segments), $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
