<?php

namespace App\Services\Concerns;

/**
 * Traduit un DTO camelCase partiellement rempli (seuls les champs fournis
 * doivent être appliqués — mise à jour partielle) en tableau de colonnes
 * snake_case prêt pour Eloquent. Centralise une boucle auparavant dupliquée
 * à l'identique dans une dizaine de Services d'entité.
 */
trait MapsFieldsToColumns
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $fieldToColumn
     * @return array<string, mixed>
     */
    protected function mapFields(array $data, array $fieldToColumn): array
    {
        $mapped = [];

        foreach ($fieldToColumn as $field => $column) {
            if (array_key_exists($field, $data)) {
                $mapped[$column] = $data[$field];
            }
        }

        return $mapped;
    }
}
