<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Résout la locale active pour la requête (FR/EN/PT/AR — voir Locale) et
 * l'applique via App::setLocale(), ce qui pilote automatiquement les
 * messages de validation Laravel (lang/{locale}/validation.php) et sert de
 * source à InteractsWithClaude::localizedInstruction() pour les prompts IA.
 *
 * Ordre de résolution : en-tête X-Locale (override explicite, utile pour
 * les tests/clients qui ne veulent pas dépendre du profil) > préférence
 * enregistrée de l'utilisateur authentifié > en-tête Accept-Language >
 * français par défaut (langue principale réelle de la plateforme).
 */
class SetLocale
{
    private const SUPPORTED = ['fr', 'en', 'pt', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        if ($header = $request->header('X-Locale')) {
            $normalized = strtolower(substr($header, 0, 2));
            if (in_array($normalized, self::SUPPORTED, true)) {
                return $normalized;
            }
        }

        try {
            $user = $request->user();
            if ($user?->locale instanceof Locale) {
                return $user->locale->value;
            }
        } catch (Throwable) {
            // Jeton absent/invalide sur une route publique — pas une erreur
            // ici, on retombe simplement sur les en-têtes/le défaut.
        }

        if ($accept = $request->header('Accept-Language')) {
            $preferred = strtolower(substr(trim(explode(',', $accept)[0]), 0, 2));
            if (in_array($preferred, self::SUPPORTED, true)) {
                return $preferred;
            }
        }

        return Locale::FR->value;
    }
}
