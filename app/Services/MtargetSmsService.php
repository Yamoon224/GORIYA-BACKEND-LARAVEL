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
 * Authentification Basic (login/mot de passe du compte MTarget, fournis par
 * leur support) — l'un des trois schémas documentés par l'API, celui
 * correspondant aux identifiants reçus pour ce compte (Bearer imposerait un
 * aller-retour /gettoken avant chaque envoi, apiKey une clé dédiée que ce
 * compte n'a pas).
 *
 * Sans MTARGET_LOGIN/MTARGET_PASSWORD configurés, no-op silencieux (log) —
 * même contrat que FcmPushNotificationService : un canal SMS absent ou en
 * échec ne doit jamais faire échouer l'action qui le déclenche (OTP,
 * notification…).
 */
class MtargetSmsService implements SmsGatewayInterface
{
    public function send(string $msisdn, string $message, ?string $sender = null): bool
    {
        $login = config('services.mtarget.login');
        $password = config('services.mtarget.password');
        if (! $login || ! $password) {
            Log::info('MTarget non configuré (MTARGET_LOGIN/MTARGET_PASSWORD) — SMS ignoré.');

            return false;
        }

        $number = $this->normalizeMsisdn($msisdn);
        if (! $number) {
            Log::warning("Numéro de téléphone invalide pour l'envoi SMS : {$msisdn}");

            return false;
        }

        try {
            $response = Http::withBasicAuth($login, $password)
                ->post(rtrim((string) config('services.mtarget.base_url'), '/').'/messages', array_filter([
                    'msg' => $message,
                    'msisdn' => $number,
                    'sender' => $sender ?: config('services.mtarget.sender'),
                    'serviceid' => config('services.mtarget.service_id'),
                ], fn ($value) => $value !== null && $value !== ''));

            // Code d'erreur métier dans le corps, même sur un 401/403/422
            // (voir la liste des codes de l'API) — 0 = ACCEPTED. Rendu tantôt
            // en entier (succès), tantôt en chaîne (erreurs) selon les
            // exemples de la doc : comparaison en (int) pour couvrir les deux.
            $code = $response->json('results.0.code');
            if ($response->failed() || $code === null || (int) $code !== 0) {
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

        // Depuis la réforme ARTCI 2021, les numéros ivoiriens sont passés à
        // 10 chiffres et le chiffre de tête (0, 1, 2...) fait partie du
        // numéro significatif — ce n'est plus un préfixe de tri à retirer
        // (contrairement à la convention française par ex.). On NE retire
        // donc PAS le 0 initial : 0565855202 → +2250565855202, pas
        // +225565855202 (qui ne correspond à aucun abonné et échoue en
        // silence côté MTarget, qui répond quand même code 0/ACCEPTED).
        return '+'.config('services.mtarget.default_country_code').$digits;
    }
}
