<?php

namespace App\Http\Concerns;

/**
 * Construit le littéral Postgres {a,b,c} attendu par l'opérateur de
 * recouvrement de tableau (&&), utilisé pour les filtres "au moins une
 * valeur correspond" (skills, participants, recommendations, ...).
 */
trait BuildsPgArrayLiterals
{
    /**
     * @param  array<int, string>  $items
     */
    protected function toPgArrayLiteral(array $items): string
    {
        $escaped = array_map(function ($item) {
            $item = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $item);

            return '"'.$item.'"';
        }, $items);

        return '{'.implode(',', $escaped).'}';
    }
}
