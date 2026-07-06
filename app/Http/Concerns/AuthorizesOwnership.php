<?php

namespace App\Http\Concerns;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Vérifie qu'un utilisateur authentifié est ADMIN ou propriétaire de la
 * ressource ciblée avant une modification/suppression. Les routes
 * update/destroy de Companies, JobOffers, Portfolios et Candidatures sont
 * ouvertes à "tout utilisateur authentifié" (auth:api seul, voir
 * routes/api.php — parité NestJS), sans contrôle de propriété : sans ce
 * garde-fou, n'importe quel compte authentifié pouvait modifier/supprimer la
 * ressource d'un tiers.
 */
trait AuthorizesOwnership
{
    protected function authorizeOwnerOrAdmin(
        ?User $actingUser,
        bool $isOwner,
        string $message = "Vous n'êtes pas autorisé à effectuer cette action sur cette ressource."
    ): void {
        if (! $actingUser) {
            abort(401);
        }

        if ($actingUser->role !== UserRole::ADMIN && ! $isOwner) {
            abort(403, $message);
        }
    }
}
