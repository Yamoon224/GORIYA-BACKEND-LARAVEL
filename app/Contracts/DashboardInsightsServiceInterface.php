<?php

namespace App\Contracts;

/**
 * Reformule les 3 recommandations du tableau de bord entreprise (déjà
 * exactes — voir CompanyRecommendationService) pour qu'elles sonnent plus
 * naturelles. N'a jamais le droit d'inventer un chiffre : seule la
 * formulation change, jamais les faits.
 */
interface DashboardInsightsServiceInterface
{
    /**
     * @param  array<string, string>  $items  Phrases déjà correctes et complètes, par clé
     * @return array<int, string>  Même nombre d'éléments, dans le même ordre
     */
    public function phraseRecommendations(array $items): array;
}
