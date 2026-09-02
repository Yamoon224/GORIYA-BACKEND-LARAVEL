<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Échange un « authorization code » LinkedIn contre le profil de
 * l'utilisateur (« Sign In with LinkedIn using OpenID Connect »).
 *
 * Contrairement à Google Identity Services, LinkedIn n'expose pas de flux
 * navigateur qui renvoie directement un ID token : le front redirige vers
 * LinkedIn, récupère un `code` à usage unique, et c'est le backend — seul
 * détenteur du `client_secret` — qui l'échange. Le code seul ne prouve donc
 * rien tant qu'il n'a pas été échangé ici : impossible pour un client de
 * fabriquer une identité, comme pour la vérification du jeton Google
 * (voir GoogleTokenVerifier).
 *
 * Le `redirect_uri` est validé contre une liste blanche (LINKEDIN_REDIRECT_URIS)
 * avant tout appel : LinkedIn exige qu'il soit identique à celui de la phase
 * d'autorisation, et l'allowlist empêche qu'un front tiers fasse échanger un
 * code pour son propre compte.
 */
class LinkedInOAuthClient
{
    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    private const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';

    /**
     * @return array{sub: string, email: string, name: ?string, picture: ?string, given_name: ?string, family_name: ?string}
     */
    public function fetchProfile(string $code, string $redirectUri): array
    {
        $clientId = (string) config('services.linkedin.client_id');
        $clientSecret = (string) config('services.linkedin.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            abort(503, "La connexion LinkedIn n'est pas configurée sur le serveur.");
        }

        $allowedRedirects = config('services.linkedin.redirect_uris', []);
        if ($allowedRedirects === []) {
            abort(503, "La connexion LinkedIn n'est pas configurée sur le serveur.");
        }

        if (! in_array($redirectUri, $allowedRedirects, true)) {
            abort(400, "L'URL de retour LinkedIn n'est pas autorisée.");
        }

        $accessToken = $this->exchangeCode($code, $redirectUri, $clientId, $clientSecret);

        return $this->fetchUserInfo($accessToken);
    }

    private function exchangeCode(string $code, string $redirectUri, string $clientId, string $clientSecret): string
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(8)->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (ConnectionException $e) {
            Log::error('LinkedIn accessToken unreachable: '.$e->getMessage());
            abort(502, 'Impossible de contacter LinkedIn pour vérifier la connexion. Réessaie.');
        }

        if (! $response->ok()) {
            // Le code LinkedIn est à usage unique et expire en ~30 s : un
            // rechargement de la page de callback tombe légitimement ici.
            Log::warning('LinkedIn accessToken rejected', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);
            abort(401, 'Code LinkedIn invalide ou expiré. Réessaie.');
        }

        $accessToken = (string) $response->json('access_token', '');

        if ($accessToken === '') {
            abort(401, 'Réponse LinkedIn incomplète.');
        }

        return $accessToken;
    }

    /**
     * @return array{sub: string, email: string, name: ?string, picture: ?string, given_name: ?string, family_name: ?string}
     */
    private function fetchUserInfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(8)->get(self::USERINFO_URL);
        } catch (ConnectionException $e) {
            Log::error('LinkedIn userinfo unreachable: '.$e->getMessage());
            abort(502, 'Impossible de contacter LinkedIn pour vérifier la connexion. Réessaie.');
        }

        if (! $response->ok()) {
            abort(401, 'Profil LinkedIn inaccessible.');
        }

        $claims = $response->json();

        if (empty($claims['sub']) || empty($claims['email'])) {
            // L'email n'est renvoyé que si le scope `email` a bien été demandé
            // et accepté par l'utilisateur.
            abort(401, "LinkedIn n'a pas communiqué d'adresse email. Autorise l'accès à ton email puis réessaie.");
        }

        $emailVerified = $claims['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            abort(401, "Cette adresse LinkedIn n'est pas vérifiée.");
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
