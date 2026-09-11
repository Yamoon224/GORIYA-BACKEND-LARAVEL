<?php

namespace App\Console\Commands;

use App\Services\PotentialPartnerImportService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Importe/rafraîchit les partenaires potentiels depuis un CSV. Sans argument,
 * rejoue le dump CCI-CI (Chambre de Commerce et d'Industrie de Côte d'Ivoire,
 * ~19 500 entreprises avec email) fourni pour le lancement du module
 * Potentiels Partenaires — idempotent par NCC, donc rejouable sans créer de
 * doublons.
 */
class ImportPotentialPartners extends Command
{
    protected $signature = 'partners:import {path? : Chemin du CSV à importer (défaut : le dump CCI-CI fourni)} {--source= : Étiquette source à enregistrer sur chaque fiche}';

    protected $description = 'Importe des partenaires potentiels depuis un CSV (module Potentiels Partenaires)';

    public function handle(PotentialPartnerImportService $importer): int
    {
        $path = $this->argument('path') ?? database_path('data/potential_partners_cci_ci.csv');
        $source = $this->option('source') ?: 'cci_ci_import';

        $this->info("Import depuis : {$path}");

        try {
            $stats = $importer->importFromCsv($path, $source);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Lignes lues : {$stats['total']}");
        $this->info("Créées : {$stats['inserted']}");
        $this->info("Mises à jour : {$stats['updated']}");
        $this->info("Ignorées (ni raison sociale ni email) : {$stats['skipped']}");

        return self::SUCCESS;
    }
}
