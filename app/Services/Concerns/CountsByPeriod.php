<?php

namespace App\Services\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Séries temporelles des tableaux de bord : le nombre de lignes par mois ou
 * par jour sort d'une seule requête GROUP BY, au lieu d'un COUNT par mois
 * affiché (6 à 12 requêtes par graphique).
 */
trait CountsByPeriod
{
    /**
     * @return array<string, int> Clés « AAAA-MM » ; un mois sans ligne est absent.
     */
    protected function countPerMonth(Builder $query, string $column, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->countPerBucket($query, $column, $from, $to, 'month');
    }

    /**
     * @return array<string, int> Clés « AAAA-MM-JJ » ; un jour sans ligne est absent.
     */
    protected function countPerDay(Builder $query, string $column, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->countPerBucket($query, $column, $from, $to, 'day');
    }

    /**
     * @return array<string, int>
     */
    private function countPerBucket(Builder $query, string $column, CarbonInterface $from, CarbonInterface $to, string $unit): array
    {
        $qualified = $query->qualifyColumn($column);
        $bucket = match ($query->getConnection()->getDriverName()) {
            'sqlite' => $unit === 'month' ? "strftime('%Y-%m', {$qualified})" : "strftime('%Y-%m-%d', {$qualified})",
            'pgsql' => $unit === 'month' ? "to_char({$qualified}, 'YYYY-MM')" : "to_char({$qualified}, 'YYYY-MM-DD')",
            default => $unit === 'month' ? "DATE_FORMAT({$qualified}, '%Y-%m')" : "DATE_FORMAT({$qualified}, '%Y-%m-%d')",
        };

        return $query
            ->whereBetween($qualified, [$from, $to])
            ->selectRaw("{$bucket} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->toBase()
            ->pluck('total', 'bucket')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
