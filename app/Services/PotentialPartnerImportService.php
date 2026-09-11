<?php

namespace App\Services;

use App\Models\PotentialPartner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import en masse de partenaires potentiels depuis un CSV (utilisé par la
 * commande `partners:import` pour le dump CCI-CI, et par l'upload CSV du
 * module admin pour les imports suivants). Colonnes attendues : voir
 * self::EXPECTED_HEADER — un CSV avec d'autres colonnes est rejeté plutôt que
 * silencieusement mal mappé.
 *
 * Upsert chunké par lots de 500 (pas un insert un par un) : le dump CCI-CI
 * fait ~19 500 lignes et un aller-retour SQL par ligne serait inutilement
 * lent pour un import qui peut être rejoué. Contourne volontairement
 * Eloquent/Auditable (voir PotentialPartner::$auditExcludes) pour cette
 * même raison de volume.
 */
class PotentialPartnerImportService
{
    private const CHUNK_SIZE = 500;

    /**
     * @var list<string>
     */
    private const EXPECTED_HEADER = [
        'NCC',
        'RAISON_SOCIALE',
        "Première année d'exercice",
        'Adresse géographique complète (Immeuble, rue, quartier, ville, pays)',
        'Commune',
        'Ville',
        'Mail',
        'Code Activité',
        'Lebellé activité',
        "M'PONI",
        'Code résumé',
        'Classification selon Go Africa',
        'Classe Go Africa',
        'Secteur',
        'Personne à contacter en cas de demande_Nom',
        'Pers0nne à c0ntacter en cas de demande_Téléph0ne',
        'Nom du signataire des états financiers',
        'Qualité du signataire des états financiers',
        'Personne ayant établi les états financiers_Nom',
        'Personne ayant établi les états financiers_Téléphone',
        'CA Tranche',
        'Tranche Effectif',
        'Taille',
    ];

    /**
     * @return array{inserted: int, updated: int, skipped: int, total: int}
     */
    public function importFromCsv(string $path, string $source = 'cci_ci_import'): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Fichier introuvable ou illisible : {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Impossible d'ouvrir le fichier : {$path}");
        }

        // Le CSV est écrit avec un BOM UTF-8 (voir extract_xlsx.php) pour
        // rester ouvrable proprement dans Excel — à retirer avant de lire
        // l'en-tête sans quoi la 1re colonne ("NCC") ne matche pas.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('CSV vide.');
        }

        if ($header !== self::EXPECTED_HEADER) {
            fclose($handle);
            throw new RuntimeException(
                "En-têtes du CSV inattendus. Colonnes attendues : \n".implode(', ', self::EXPECTED_HEADER)
            );
        }

        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];
        $buffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $stats['total']++;

            $mapped = $this->mapRow($row, $source);
            if ($mapped === null) {
                $stats['skipped']++;

                continue;
            }

            $buffer[] = $mapped;

            if (count($buffer) >= self::CHUNK_SIZE) {
                $this->upsertChunk($buffer, $stats);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->upsertChunk($buffer, $stats);
        }

        fclose($handle);

        return $stats;
    }

    /**
     * @return array<string, mixed>|null  null si la ligne n'a ni raison sociale ni email exploitable
     */
    private function mapRow(array $row, string $source): ?array
    {
        $col = fn (int $i) => $this->clean($row[$i] ?? null);

        $companyName = $col(1);
        $email = $col(6);

        if ($companyName === null && $email === null) {
            return null;
        }

        $email = $email !== null ? Str::lower($email) : null;

        $raw = [
            'code_activite' => $col(7),
            'mponi' => $col(9),
            'code_resume' => $col(10),
            'classification_go_africa' => $col(11),
            'classe_go_africa' => $col(12),
            'signataire_etats_financiers_nom' => $col(16),
            'signataire_etats_financiers_qualite' => $col(17),
            'redacteur_etats_financiers_nom' => $col(18),
            'redacteur_etats_financiers_telephone' => $col(19),
        ];

        return [
            'id' => (string) Str::uuid(),
            'ncc' => $col(0),
            'company_name' => $companyName ?? $email,
            'email' => $email,
            'email_valid' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
            'contact_name' => $col(14),
            'contact_phone' => $col(15),
            'sector' => $col(13),
            'activity_label' => $col(8),
            'city' => $col(5),
            'commune' => $col(4),
            'address' => $col(3),
            'company_size' => $col(22),
            'ca_tranche' => $col(20),
            'workforce_tranche' => $col(21),
            'first_exercise_year' => $col(2),
            'status' => 'new',
            'source' => $source,
            'raw_data' => json_encode(array_filter($raw, fn ($v) => $v !== null)),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Le dump source utilise "ND" (non disponible) comme valeur d'absence
     * plutôt qu'une cellule vide — normalisé en null comme le reste des
     * champs manquants.
     */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || strtoupper($value) === 'ND') {
            return null;
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @param  array{inserted: int, updated: int, skipped: int, total: int}  $stats
     */
    private function upsertChunk(array $chunk, array &$stats): void
    {
        // Les lignes sans NCC (aucune valeur unique à matcher) sont toujours
        // insérées telles quelles : on ne peut pas savoir si elles existent
        // déjà, et un doublon occasionnel est préférable à en perdre.
        $withNcc = array_values(array_filter($chunk, fn ($r) => $r['ncc'] !== null));
        $withoutNcc = array_values(array_filter($chunk, fn ($r) => $r['ncc'] === null));

        if ($withoutNcc !== []) {
            DB::table('potential_partners')->insert($withoutNcc);
            $stats['inserted'] += count($withoutNcc);
        }

        if ($withNcc !== []) {
            $existing = DB::table('potential_partners')
                ->whereIn('ncc', array_column($withNcc, 'ncc'))
                ->pluck('id', 'ncc');

            DB::table('potential_partners')->upsert(
                $withNcc,
                ['ncc'],
                ['company_name', 'email', 'email_valid', 'contact_name', 'contact_phone', 'sector',
                    'activity_label', 'city', 'commune', 'address', 'company_size', 'ca_tranche',
                    'workforce_tranche', 'first_exercise_year', 'raw_data', 'updated_at']
            );

            foreach ($withNcc as $row) {
                $existing->has($row['ncc']) ? $stats['updated']++ : $stats['inserted']++;
            }
        }
    }
}
