<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Services\ApiClientService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `auth.apikey` du namespace /external/v1/* (API B2B) — distinct
 * de `auth:api` (JWT utilisateur, guard Laravel standard). Alias simple
 * plutôt qu'un guard `auth:` custom : pas besoin du cycle
 * Authenticatable/provider complet pour une simple résolution de jeton
 * opaque. Le jeton Bearer est résolu vers un
 * ApiClient, bindé dans le conteneur pour le reste de la requête (voir
 * ExternalCandidaturesController, qui le type-hint directement) et exposé
 * à RateLimiter::for('api-client') via $request->attributes.
 */
class EnsureValidApiKey
{
    public function __construct(private readonly ApiClientService $apiClientService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            abort(401, 'Clé API manquante (Authorization: Bearer <token>)');
        }

        $client = $this->apiClientService->resolveByToken($token);

        if (! $client) {
            abort(401, 'Clé API invalide ou révoquée');
        }

        $this->apiClientService->touchLastUsed($client);

        app()->instance(ApiClient::class, $client);
        $request->attributes->set('api_client', $client);

        return $next($request);
    }
}
