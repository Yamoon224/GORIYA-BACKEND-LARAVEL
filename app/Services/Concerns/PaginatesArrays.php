<?php

namespace App\Services\Concerns;

/**
 * Pagination en mémoire sur un tableau déjà chargé — pas un paginator
 * Eloquent. Utilisé partout où la source NestJS pagine un tableau après
 * l'avoir chargé en entier (historique d'entretiens, recherche globale),
 * plutôt qu'au niveau SQL — comportement à préserver tel quel. Partagé
 * entre AdminReportingService et AdminSearchService.
 */
trait PaginatesArrays
{
    /**
     * @return array{data: array, meta: array}
     */
    protected function paginateArray(array $items, int $page, int $limit): array
    {
        $total = count($items);
        $safeLimit = max(1, $limit);
        $safePage = max(1, $page);
        $start = ($safePage - 1) * $safeLimit;

        return [
            'data' => array_slice($items, $start, $safeLimit),
            'meta' => [
                'total' => $total,
                'page' => $safePage,
                'limit' => $safeLimit,
                'totalPages' => (int) (ceil($total / $safeLimit) ?: 1),
            ],
        ];
    }
}
