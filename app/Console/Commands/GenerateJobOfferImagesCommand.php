<?php

namespace App\Console\Commands;

use App\Models\JobOffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Génère une image de couverture déterministe (dégradé + initiale) pour
 * chaque offre d'emploi sans image — pas d'API externe, résultat stable
 * (même offre => toujours la même image), suffisant pour habiller les
 * cartes d'offres tant qu'aucune image n'est uploadée par l'entreprise.
 */
class GenerateJobOfferImagesCommand extends Command
{
    protected $signature = 'job-offers:generate-images {--force : Régénère aussi les offres qui ont déjà une image}';

    protected $description = "Génère une image de couverture pour les offres d'emploi qui n'en ont pas";

    /**
     * Paires de dégradés cohérentes avec la charte bleue de l'app (#2f6de6),
     * avec quelques variantes pour distinguer visuellement les offres.
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
        $query = JobOffer::query()->with('company');

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('image')->orWhere('image', '');
            });
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info("Aucune offre à traiter — toutes ont déjà une image (utilise --force pour régénérer).");

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(50, function ($jobOffers) use ($bar) {
            foreach ($jobOffers as $jobOffer) {
                $path = $this->generateFor($jobOffer);
                $jobOffer->update(['image' => $path]);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("{$total} image(s) générée(s).");

        return self::SUCCESS;
    }

    private function generateFor(JobOffer $jobOffer): string
    {
        $label = $jobOffer->company?->name ?: $jobOffer->title;
        $initial = mb_strtoupper(mb_substr(trim($label) ?: '?', 0, 1));

        [$from, $to] = self::GRADIENTS[crc32($jobOffer->id) % count(self::GRADIENTS)];

        $svg = $this->buildSvg($initial, $from, $to);
        $filename = "{$jobOffer->id}.svg";

        Storage::disk('public')->put("job-offers/{$filename}", $svg);

        return "/job-offers/{$filename}";
    }

    private function buildSvg(string $initial, string $from, string $to): string
    {
        $initial = htmlspecialchars($initial, ENT_QUOTES | ENT_XML1);
        $gradientId = 'g'.substr(md5($from.$to), 0, 8);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450">
    <defs>
        <linearGradient id="{$gradientId}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="{$from}" />
            <stop offset="100%" stop-color="{$to}" />
        </linearGradient>
    </defs>
    <rect width="800" height="450" fill="url(#{$gradientId})" />
    <circle cx="660" cy="90" r="180" fill="#ffffff" opacity="0.08" />
    <circle cx="120" cy="400" r="140" fill="#ffffff" opacity="0.08" />
    <text x="400" y="245" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="140" font-weight="700" fill="#ffffff" opacity="0.9">{$initial}</text>
</svg>
SVG;
    }
}
