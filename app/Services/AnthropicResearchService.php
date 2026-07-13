<?php

namespace App\Services;

use App\Contracts\CompanyResearchServiceInterface;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Génère la recherche IA sur une entreprise pour Goriya IA Research. Comme
 * AnthropicService, sans ANTHROPIC_API_KEY configurée chaque appel retourne
 * une valeur de repli statique (comportement attendu en dev, pas une erreur).
 */
class AnthropicResearchService implements CompanyResearchServiceInterface
{
    use InteractsWithClaude;

    private const RESEARCH_FALLBACK = [
        'historique' => "Aucune information disponible pour le moment sur l'historique de cette entreprise.",
        'valeurs' => ['Information non disponible'],
        'culture' => 'Information non disponible.',
        'actualites' => ['Aucune actualité récente trouvée'],
        'synthese' => "Recherche indisponible actuellement — réessayez plus tard ou complétez manuellement votre préparation d'entretien.",
        'recommandations' => [
            "Consultez le site officiel de l'entreprise avant l'entretien",
            "Recherchez l'entreprise sur LinkedIn pour ses actualités récentes",
            'Préparez des questions sur sa culture et ses valeurs',
        ],
    ];

    public function __construct()
    {
        $this->initClaudeClient();
    }

    public function research(string $companyName): array
    {
        $fallback = self::RESEARCH_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $prompt = <<<PROMPT
Vous êtes un assistant de recherche RH spécialisé dans l'univers professionnel. Un candidat se prépare à un entretien avec l'entreprise suivante : {$companyName}.

Retournez UNIQUEMENT un objet JSON valide (sans markdown, sans texte avant ou après) avec exactement cette structure :
{
  "historique": "<résumé de l'historique et du positionnement de l'entreprise, 2-3 phrases>",
  "valeurs": ["<valeur 1>", "<valeur 2>", "<valeur 3>"],
  "culture": "<description de la culture d'entreprise, 2-3 phrases>",
  "actualites": ["<actualité ou tendance récente 1>", "<actualité ou tendance récente 2>"],
  "synthese": "<synthèse actionnable pour se préparer à l'entretien, 2-3 phrases>",
  "recommandations": ["<recommandation actionnable 1>", "<recommandation actionnable 2>", "<recommandation actionnable 3>"]
}

Basez-vous sur vos connaissances générales de l'entreprise et de son secteur. Si vous n'avez pas d'information fiable sur une entreprise précise, restez honnête et généraliste plutôt que d'inventer des faits. Répondez en français. Minimum 2 éléments par tableau.
PROMPT;

            $text = $this->requestClaudeText($prompt, 1024);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return [
                'historique' => (string) ($parsed['historique'] ?? $fallback['historique']),
                'valeurs' => $this->ensureClaudeStringArray($parsed['valeurs'] ?? null, $fallback['valeurs']),
                'culture' => (string) ($parsed['culture'] ?? $fallback['culture']),
                'actualites' => $this->ensureClaudeStringArray($parsed['actualites'] ?? null, $fallback['actualites']),
                'synthese' => (string) ($parsed['synthese'] ?? $fallback['synthese']),
                'recommandations' => $this->ensureClaudeStringArray($parsed['recommandations'] ?? null, $fallback['recommandations']),
            ];
        } catch (Throwable $e) {
            Log::error('Company research failed: '.$e->getMessage());

            return $fallback;
        }
    }
}
