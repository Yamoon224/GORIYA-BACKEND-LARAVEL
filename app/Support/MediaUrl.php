<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Convertit un chemin de média stocké en base en URL absolue servable.
 *
 * Historique : `CompanyService::storeCompanyFile()` a longtemps renvoyé
 * `"/companies/<uuid>.ext"` (sans `/storage`, sans domaine) — inexploitable
 * côté front (apps sur des domaines distincts). Ce helper normalise :
 *   - null / vide                         -> null
 *   - URL déjà absolue (http/https)       -> inchangée (données seed)
 *   - "/storage/…"                        -> APP_URL + chemin
 *   - "/companies/…" ou "companies/…"     -> APP_URL + "/storage/" + chemin
 */
class MediaUrl
{
    public static function resolve(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $base = rtrim((string) config('app.url'), '/');
        $path = '/'.ltrim($path, '/');

        if (! Str::startsWith($path, '/storage/')) {
            $path = '/storage'.$path;
        }

        return $base.$path;
    }
}
