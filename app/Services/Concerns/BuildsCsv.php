<?php

namespace App\Services\Concerns;

/**
 * Construction de CSV à partir de lignes associatives — partagé entre
 * AdminReportingService (exportUsersCsv) et AdminSearchService
 * (exportSearchCsv), auparavant dupliqué au sein d'AdminPlatformService.
 */
trait BuildsCsv
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function toCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($header) => $this->escapeCsvValue($row[$header] ?? null), $headers));
        }

        return implode("\n", $lines);
    }

    protected function escapeCsvValue(mixed $value): string
    {
        // PHP fatals on (string) casting an array, unlike JS's implicit
        // Array.prototype.toString (comma-join) — replicate that join so a
        // row containing e.g. `requirements`/`company` (arrays/objects from
        // the heterogeneous candidate+offer export) doesn't crash the export.
        if (is_array($value)) {
            $stringValue = implode(',', array_map(
                fn ($item) => is_array($item) ? implode(',', $item) : (string) $item,
                $value
            ));
        } else {
            $stringValue = (string) ($value ?? '');
        }

        if (str_contains($stringValue, ',') || str_contains($stringValue, '"') || str_contains($stringValue, "\n")) {
            return '"'.str_replace('"', '""', $stringValue).'"';
        }

        return $stringValue;
    }
}
