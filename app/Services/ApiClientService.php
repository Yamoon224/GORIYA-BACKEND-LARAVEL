<?php

namespace App\Services;

use App\Models\ApiClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Émission et gestion des identifiants API B2B — le jeton en clair n'est
 * jamais persisté ni loggé, seul son hash SHA-256 l'est (voir migration).
 */
class ApiClientService
{
    private const TOKEN_PREFIX = 'goriya_';

    public function listForCompany(string $companyId): Collection
    {
        return ApiClient::where('company_id', $companyId)->orderByDesc('created_at')->get();
    }

    public function find(string $id, string $companyId): ?ApiClient
    {
        return ApiClient::where('company_id', $companyId)->find($id);
    }

    /**
     * @return array{client: ApiClient, token: string}  $token n'est disponible qu'ici, une seule fois
     */
    public function create(string $companyId, string $name, bool $isSandbox = true): array
    {
        $token = self::TOKEN_PREFIX.Str::random(40);

        $client = ApiClient::create([
            'company_id' => $companyId,
            'name' => $name,
            'token_hash' => $this->hashToken($token),
            'is_sandbox' => $isSandbox,
            'is_active' => true,
        ]);

        return ['client' => $client, 'token' => $token];
    }

    public function revoke(ApiClient $client): void
    {
        $client->update(['is_active' => false]);
    }

    public function resolveByToken(string $token): ?ApiClient
    {
        return ApiClient::where('token_hash', $this->hashToken($token))
            ->where('is_active', true)
            ->first();
    }

    public function touchLastUsed(ApiClient $client): void
    {
        $client->update(['last_used_at' => now()]);
    }

    /**
     * SHA-256 simple (pas bcrypt/argon2) : le jeton a déjà 40 caractères
     * aléatoires (~238 bits d'entropie), pas besoin d'un hash lent — même
     * choix que les personal access tokens Sanctum.
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
