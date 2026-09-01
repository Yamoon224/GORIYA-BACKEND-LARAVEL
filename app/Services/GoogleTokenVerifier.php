<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie un jeton d'identité Google (ID token JWT émis par Google Identity
 * Services) côté serveur. Sans cette vérification, le backend faisait
 * aveuglément confiance à l'email envoyé par le navigateur : n'importe qui
 * pouvait ouvrir une session au nom de n'importe quel utilisateur.
 *
 * On délègue la validation cryptographique (signature, expiration, format) à
 * l'endpoint officiel `tokeninfo` de Google, puis on contrôle nous-mêmes
 * l'émetteur, l'audience (Client ID) et la vérification de l'email.
 */
class GoogleTokenVerifier
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    private const VALID_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @param  list<string>  $allowedClientIds
     * @return array{sub: string, email: string, name: ?string, picture: ?string, given_name: ?string, family_name: ?string}
     */
    public function verify(string $idToken, array $allowedClientIds): array
    {
        if (trim($idToken) === '') {
            abort(401, 'Jeton Google manquant.');
        }

        if ($allowedClientIds === []) {
            abort(503, "La connexion Google n'est pas configurée sur le serveur.");
        }

        try {
            $response = Http::acceptJson()->timeout(8)->get(self::TOKENINFO_URL, ['id_token' => $idToken]);
        } catch (ConnectionException $e) {
            Log::error('Google tokeninfo unreachable: '.$e->getMessage());
            abort(502, 'Impossible de contacter Google pour vérifier la connexion. Réessaie.');
        }

        if (! $response->ok()) {
            abort(401, 'Jeton Google invalide ou expiré.');
        }

        $claims = $response->json();

        if (! in_array($claims['iss'] ?? '', self::VALID_ISSUERS, true)) {
            abort(401, 'Émetteur du jeton Google inattendu.');
        }

        if (! in_array($claims['aud'] ?? '', $allowedClientIds, true)) {
            abort(401, 'Jeton Google émis pour une autre application.');
        }

        $emailVerified = $claims['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            abort(401, 'Cette adresse Google n\'est pas vérifiée.');
        }

        if (empty($claims['sub']) || empty($claims['email'])) {
            abort(401, 'Jeton Google incomplet.');
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => mb_strtolower(trim((string) $claims['email'])),
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
            'picture' => isset($claims['picture']) ? (string) $claims['picture'] : null,
            'given_name' => isset($claims['given_name']) ? (string) $claims['given_name'] : null,
            'family_name' => isset($claims['family_name']) ? (string) $claims['family_name'] : null,
        ];
    }
}
