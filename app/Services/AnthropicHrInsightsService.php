<?php

namespace App\Services;

use App\Contracts\HrInsightsServiceInterface;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyse les réponses texte agrégées d'une EmployeeSurvey pour en extraire
 * tendances, points de friction et recommandations RH — jamais de données
 * nominatives en entrée (voir HrInsightsServiceInterface). Comme les autres
 * services Anthropic*, sans ANTHROPIC_API_KEY configurée chaque appel
 * retourne une valeur de repli statique.
 */
class AnthropicHrInsightsService implements HrInsightsServiceInterface
{
    use InteractsWithClaude;

    private const FALLBACK = [
        'trends' => ['Analyse indisponible actuellement — réessayez plus tard.'],
        'frictionPoints' => [],
        'recommendations' => ["Consultez les réponses individuelles pour une lecture manuelle en attendant."],
    ];

    public function __construct()
    {
        $this->initClaudeClient();
    }

    public function analyzeSurveyResponses(array $textAnswers): array
    {
        $fallback = self::FALLBACK;

        if (! $this->hasClaudeClient() || $textAnswers === []) {
            return $fallback;
        }

        try {
            $answersBlock = collect($textAnswers)
                ->map(fn (string $a, int $i) => ($i + 1).'. '.$this->truncateForClaude($a, 500))
                ->implode("\n");

            $prompt = <<<PROMPT
Vous êtes un consultant RH spécialisé en analyse du climat social. Voici des réponses libres, anonymes et agrégées, issues d'une enquête interne auprès des employés d'une entreprise :

{$this->truncateForClaude($answersBlock, 6000)}

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{
  "trends": ["<tendance générale observée 1>", "<tendance 2>"],
  "frictionPoints": ["<point de friction concret 1>", "<point de friction 2>"],
  "recommendations": ["<recommandation actionnable pour les RH 1>", "<recommandation 2>"]
}

{$this->localizedInstruction()} Restez factuel et basé uniquement sur le contenu fourni, sans extrapoler sur des individus.
PROMPT;

            $text = $this->requestClaudeText($prompt, 1024);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return [
                'trends' => $this->ensureClaudeStringArray($parsed['trends'] ?? null, $fallback['trends']),
                'frictionPoints' => $this->ensureClaudeStringArray($parsed['frictionPoints'] ?? null, $fallback['frictionPoints']),
                'recommendations' => $this->ensureClaudeStringArray($parsed['recommendations'] ?? null, $fallback['recommendations']),
            ];
        } catch (Throwable $e) {
            Log::error('HR insights analysis failed: '.$e->getMessage());

            return $fallback;
        }
    }
}
