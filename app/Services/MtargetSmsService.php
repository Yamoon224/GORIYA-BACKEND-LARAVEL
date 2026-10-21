<?php

namespace App\Services;

use App\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi de SMS via l'API MTarget — https://developers.m-target.com,
 * endpoint "Send a simple SMS Message" (POST /messages).
 *
 * Authentification par API Key (header X-API-KEY) : le plus simple des trois
 * schémas proposés par l'API (Basic imposerait les identifiants en clair à
 * chaque appel, Bearer un aller-retour /gettoken avant chaque envoi).
 *
 * Sans MTARGET_API_KEY configurée, no-op silencieux (log) — même contrat que
 * FcmPushNotificationService : un canal SMS absent ou en échec ne doit
 * jamais faire échouer l'action qui le déclenche (OTP, notification…).
 */
class MtargetSmsService implements SmsGatewayInterface
{
    public function send(string $msisdn, string $message, ?string $sender = null): bool
    {
        $apiKey = config('services.mtarget.api_key');
        if (! $apiKey) {
            Log::info('MTarget non configuré (MTARGET_API_KEY) — SMS ignoré.');

            return false;
        }

        $number = $this->normalizeMsisdn($msisdn);
        if (! $number) {
            Log::warning("Numéro de téléphone invalide pour l'envoi SMS : {$msisdn}");

            return false;
        }

        try {
            $response = Http::withHeaders(['X-API-KEY' => $apiKey])
                ->post(rtrim((string) config('services.mtarget.base_url'), '/').'/messages', [
                    'msg' => $message,
                    'msisdn' => $number,
                    'sender' => $sender ?: config('services.mtarget.sender'),
                ]);

            // Réponse 200 avec un code d'erreur métier dans le corps possible
            // (voir la liste des codes de l'API) — 0 = ACCEPTED.
            $code = $response->json('results.0.code');
            if ($response->failed() || $code !== 0) {
                Log::warning('Échec envoi SMS MTarget: '.$response->body());

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Exception envoi SMS MTarget: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Les numéros saisis dans l'app ne sont pas garantis en format
     * international (champs `phone` libres, voir UpdateMyProfileRequest) :
     * un numéro local (sans +) est préfixé par l'indicatif par défaut
     * (Côte d'Ivoire).
     */
    private function normalizeMsisdn(string $msisdn): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', $msisdn) ?? '';
        if ($digits === '' || $digits === '+') {
            return null;
        }

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        return '+'.config('services.mtarget.default_country_code').ltrim($digits, '0');
    }
}
