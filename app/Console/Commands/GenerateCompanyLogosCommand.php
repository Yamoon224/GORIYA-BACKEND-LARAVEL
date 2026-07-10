<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Génère un logo déterministe (dégradé + initiale) pour chaque entreprise
 * sans logo — pas d'API externe, résultat stable (même entreprise =>
 * toujours le même logo), suffisant tant qu'aucun logo n'est uploadé.
 */
class GenerateCompanyLogosCommand extends Command
{
    protected $signature = 'companies:generate-logos {--force : Régénère aussi les entreprises qui ont déjà un logo}';

    protected $description = "Génère un logo pour les entreprises qui n'en ont pas";

    /**
     * Paires de dégradés cohérentes avec la charte bleue de l'app (#2f6de6),
     * avec quelques variantes pour distinguer visuellement les entreprises.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const GRADIENTS = [
        ['#2f6de6', '#1b3fae'],
        ['#1e7df2', '#123d8f'],
        ['#4f7df0', '#22348f'],
        ['#2fb6e6', '#1b6cae'],
        ['#6a5ff2', '#2c1f8f'],
        ['#2fe6b0', '#1bae7c'],
        ['#f2a83f', '#c9701b'],
        ['#e64f8f', '#8f1b52'],
    ];

    public function handle(): int
    {
        $query = Company::query();

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('logo')->orWhere('logo', '');
            });
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info("Aucune entreprise à traiter — toutes ont déjà un logo (utilise --force pour régénérer).");

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(50, function ($companies) use ($bar) {
            foreach ($companies as $company) {
                $path = $this->generateFor($company);
                $company->update(['logo' => $path]);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("{$total} logo(s) généré(s).");

        return self::SUCCESS;
    }

    private function generateFor(Company $company): string
    {
        $label = $company->name ?: '?';
        $initial = mb_strtoupper(mb_substr(trim($label) ?: '?', 0, 1));

        [$from, $to] = self::GRADIENTS[crc32($company->id) % count(self::GRADIENTS)];

        $svg = $this->buildSvg($initial, $from, $to);
        $filename = "{$company->id}.svg";

        Storage::disk('public')->put("companies/{$filename}", $svg);

        return "/companies/{$filename}";
    }

    private function buildSvg(string $initial, string $from, string $to): string
    {
        $initial = htmlspecialchars($initial, ENT_QUOTES | ENT_XML1);
        $gradientId = 'g'.substr(md5($from.$to), 0, 8);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">
    <defs>
        <linearGradient id="{$gradientId}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="{$from}" />
            <stop offset="100%" stop-color="{$to}" />
        </linearGradient>
    </defs>
    <rect width="400" height="400" rx="40" fill="url(#{$gradientId})" />
    <circle cx="330" cy="60" r="90" fill="#ffffff" opacity="0.08" />
    <circle cx="60" cy="340" r="70" fill="#ffffff" opacity="0.08" />
    <text x="200" y="255" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="180" font-weight="700" fill="#ffffff" opacity="0.9">{$initial}</text>
</svg>
SVG;
    }
}
