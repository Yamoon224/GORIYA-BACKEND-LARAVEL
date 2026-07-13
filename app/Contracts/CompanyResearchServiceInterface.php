<?php

namespace App\Contracts;

/**
 * Frontière vers le fournisseur d'analyse IA utilisé par Goriya IA Research
 * — implémentée par AnthropicResearchService. Distincte de
 * AiAnalysisServiceInterface (CV/scoring/matching) : Research porte sur des
 * entreprises, pas des candidats.
 */
interface CompanyResearchServiceInterface
{
    /**
     * @return array{
     *     historique: string,
     *     valeurs: array<int, string>,
     *     culture: string,
     *     actualites: array<int, string>,
     *     synthese: string,
     *     recommandations: array<int, string>
     * }
     */
    public function research(string $companyName): array;
}
