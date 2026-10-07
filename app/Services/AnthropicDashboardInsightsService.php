<?php

namespace App\Services;

use App\Contracts\DashboardInsightsServiceInterface;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reformule les recommandations du tableau de bord entreprise calculées par
 * CompanyRecommendationService. Comme les autres services Anthropic*, sans
 * ANTHROPIC_API_KEY configurée chaque appel renvoie les phrases d'origine
 * telles quelles — déjà correctes, juste moins naturelles.
 */
class AnthropicDashboardInsightsService implements DashboardInsightsServiceInterface
{
    use InteractsWithClaude;

    public function __construct()
    {
        $this->initClaudeClient();
    }

    public function phraseRecommendations(array $items): array
    {
        $fallback = array_values($items);

        if (! $this->hasClaudeClient() || $fallback === []) {
            return $fallback;
        }

        try {
            $numbered = collect($fallback)
                ->map(fn (string $t, int $i) => ($i + 1).'. '.$this->truncateForClaude($t, 400))
                ->implode("\n");

            $prompt = <<<PROMPT
Voici {$this->countItems($fallback)} recommandations déjà correctes pour le tableau de bord d'un recruteur, basées sur ses propres statistiques réelles :

{$numbered}

Reformulez-les pour qu'elles sonnent plus naturelles et percutantes, SANS changer un seul chiffre, fait ou pourcentage qu'elles contiennent, et sans en inventer un nouveau. Gardez exactement le même nombre de phrases, dans le même ordre, chacune en une seule phrase courte.

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{"items": ["<phrase 1>", "<phrase 2>", "<phrase 3>"]}

{$this->localizedInstruction()}
PROMPT;

            // Le tableau de bord est rechargé à chaque visite, mais ses phrases
            // ne changent que lorsque les chiffres changent : tant qu'ils sont
            // identiques, la reformulation déjà obtenue est resservie sans
            // nouvel appel à l'IA.
            return $this->rememberClaudeResult('dashboard-recommendations', $fallback, 24, function () use ($prompt, $fallback) {
                $text = $this->requestClaudeText($prompt, 512);
                $parsed = $this->parseClaudeJson($text, ['items' => $fallback]);
                $rephrased = $this->ensureClaudeStringArray($parsed['items'] ?? null, $fallback);

                return count($rephrased) === count($fallback) && $rephrased !== $fallback ? $rephrased : null;
            }) ?? $fallback;
        } catch (Throwable $e) {
            Log::error('Dashboard insights phrasing failed: '.$e->getMessage());

            return $fallback;
        }
    }

    private function countItems(array $items): int
    {
        return count($items);
    }
}
