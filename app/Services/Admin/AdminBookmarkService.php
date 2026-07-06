<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;

/**
 * Mirroir du sous-ensemble "follow/save" de backend/src/admin/admin-platform.service.ts.
 * Aucune entité DB dédiée côté source (Map en mémoire sur le singleton
 * NestJS) — persistance via Cache pour survivre au modèle sans état
 * par-requête de PHP-FPM. Extrait de l'ex-AdminPlatformService : une seule
 * responsabilité (bascules éphémères), zéro dépendance à un repository.
 */
class AdminBookmarkService
{
    public function followCompany(string $companyId, string $userId = 'admin'): void
    {
        $key = "admin:followed_companies:{$companyId}";
        $set = Cache::get($key, []);
        $set[$userId] = true;
        Cache::forever($key, $set);
    }

    public function unfollowCompany(string $companyId, string $userId = 'admin'): void
    {
        $key = "admin:followed_companies:{$companyId}";
        $set = Cache::get($key, []);
        unset($set[$userId]);
        Cache::forever($key, $set);
    }

    public function saveJob(string $jobId, string $userId = 'admin'): void
    {
        $key = "admin:saved_jobs:{$jobId}";
        $set = Cache::get($key, []);
        $set[$userId] = true;
        Cache::forever($key, $set);
    }

    public function unsaveJob(string $jobId, string $userId = 'admin'): void
    {
        $key = "admin:saved_jobs:{$jobId}";
        $set = Cache::get($key, []);
        unset($set[$userId]);
        Cache::forever($key, $set);
    }
}
