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
 *
 * La recherche est d'abord tentée avec consultation du web (dirigeants,
 * dates, chiffres, actualités vérifiables) ; si l'outil de recherche est
 * indisponible, elle retombe sur les seules connaissances du modèle.
 */
class AnthropicResearchService implements CompanyResearchServiceInterface
{
    use InteractsWithClaude;

    /** Nombre maximal de requêtes web par recherche (coût et durée). */
    private const MAX_WEB_SEARCHES = 5;

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

        // Plusieurs requêtes web puis la rédaction : bien au-delà des 30 s par défaut.
        @set_time_limit(150);

        try {
            $web = $this->requestClaudeWebResearch($this->prompt($companyName, true), 4096, self::MAX_WEB_SEARCHES);
            $parsed = $this->parseClaudeJson($web['text'], []);

            if ($parsed !== []) {
                return $this->normalize($parsed, $fallback) + [
                    'sources' => array_slice($web['sources'], 0, 8),
                    'mode' => 'web',
                ];
            }

            Log::warning('Company research: web answer without JSON, falling back to model knowledge.');
        } catch (Throwable $e) {
            Log::warning('Company research: web search unavailable ('.$e->getMessage().'), falling back to model knowledge.');
        }

        try {
            $text = $this->requestClaudeText($this->prompt($companyName, false), 3000);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return $this->normalize($parsed, $fallback) + ['sources' => [], 'mode' => 'connaissances'];
        } catch (Throwable $e) {
            Log::error('Company research failed: '.$e->getMessage());

            return $fallback;
        }
    }

    private function prompt(string $companyName, bool $withWeb): string
    {
        $method = $withWeb
            ? <<<'TEXT'
Méthode : utilisez la recherche web pour établir les faits avant de répondre. Menez plusieurs recherches ciblées : site officiel et page « À propos », dirigeants (directeur général, PDG, fondateur, président du conseil), historique et dates clés, chiffres (effectif, chiffre d'affaires, implantations), actualités des douze derniers mois, avis d'employés et culture. Privilégiez le site officiel, LinkedIn, la presse économique et les registres d'entreprises. Si le nom est ambigu, retenez l'entité la plus plausible pour un candidat d'Afrique de l'Ouest francophone (souvent la filiale locale) et précisez-le dans la présentation.
TEXT
            : <<<'TEXT'
Méthode : la recherche web n'est pas disponible, basez-vous sur vos connaissances de l'entreprise et de son secteur.
TEXT;

        return <<<PROMPT
Vous êtes un analyste qui prépare un dossier d'entreprise approfondi pour un candidat avant son entretien d'embauche. Entreprise : {$companyName}.

{$method}

Exigence de fiabilité : ne rapportez que des faits établis. N'inventez jamais un nom de dirigeant, une date, un chiffre ou une actualité. Quand une information est introuvable ou incertaine, laissez la chaîne vide "" ou le tableau vide [] : un champ vide vaut mieux qu'une information fausse. Datez les chiffres et les actualités quand c'est possible.

Retournez UNIQUEMENT un objet JSON valide (sans markdown, sans balises de citation, sans texte avant ou après) avec exactement cette structure :
{
  "presentation": "<qui est l'entreprise, ce qu'elle fait et pour qui, 2-3 phrases précises>",
  "fiche": {
    "secteur": "<secteur d'activité>",
    "creation": "<année de création>",
    "siege": "<ville, pays du siège>",
    "effectif": "<nombre ou ordre de grandeur de salariés>",
    "siteWeb": "<URL du site officiel>",
    "groupe": "<maison mère ou groupe d'appartenance, s'il y en a un>"
  },
  "dirigeants": [{"nom": "<prénom nom>", "role": "<fonction exacte : Directeur Général, PDG, fondateur...>"}],
  "historique": "<histoire détaillée : fondation, fondateurs, grandes étapes, évolutions récentes, 5 à 8 phrases>",
  "dates": [{"annee": "<année>", "evenement": "<étape marquante>"}],
  "activites": ["<produit, service ou métier principal>"],
  "chiffres": ["<chiffre clé daté : chiffre d'affaires, clients, implantations, parts de marché...>"],
  "culture": "<culture d'entreprise, management, conditions de travail, ce qu'en disent les employés, 3-4 phrases>",
  "valeurs": ["<valeur affichée ou constatée>"],
  "actualites": ["<actualité récente datée>"],
  "concurrents": ["<concurrent principal>"],
  "synthese": "<ce que le candidat doit absolument retenir et mettre en avant en entretien, 3-4 phrases>",
  "recommandations": ["<conseil concret et propre à cette entreprise pour l'entretien>"],
  "questions": ["<question pertinente que le candidat peut poser au recruteur, fondée sur ce dossier>"]
}

Listes : 3 à 6 éléments quand l'information existe (dirigeants : tous ceux identifiés de façon fiable, 5 au plus). {$this->localizedInstruction()}
PROMPT;
    }

    /**
     * Ramène la réponse du modèle à la structure stockée ; tout champ absent
     * ou mal formé devient vide plutôt que de casser l'affichage.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function normalize(array $parsed, array $fallback): array
    {
        $text = fn (mixed $v): string => is_scalar($v) ? $this->stripCitations((string) $v) : '';
        $list = fn (mixed $v): array => collect(is_array($v) ? $v : [])
            ->map($text)
            ->filter(fn (string $s) => $s !== '')
            ->take(8)
            ->values()
            ->all();

        $fiche = is_array($parsed['fiche'] ?? null) ? $parsed['fiche'] : [];

        return [
            'presentation' => $text($parsed['presentation'] ?? ''),
            'fiche' => collect(['secteur', 'creation', 'siege', 'effectif', 'siteWeb', 'groupe'])
                ->mapWithKeys(fn (string $key) => [$key => $text($fiche[$key] ?? '')])
                ->all(),
            'dirigeants' => collect(is_array($parsed['dirigeants'] ?? null) ? $parsed['dirigeants'] : [])
                ->filter(fn ($d) => is_array($d))
                ->map(fn (array $d) => ['nom' => $text($d['nom'] ?? ''), 'role' => $text($d['role'] ?? '')])
                ->filter(fn (array $d) => $d['nom'] !== '')
                ->take(5)
                ->values()
                ->all(),
            'historique' => $text($parsed['historique'] ?? '') ?: $fallback['historique'],
            'dates' => collect(is_array($parsed['dates'] ?? null) ? $parsed['dates'] : [])
                ->filter(fn ($d) => is_array($d))
                ->map(fn (array $d) => ['annee' => $text($d['annee'] ?? ''), 'evenement' => $text($d['evenement'] ?? '')])
                ->filter(fn (array $d) => $d['evenement'] !== '')
                ->take(8)
                ->values()
                ->all(),
            'activites' => $list($parsed['activites'] ?? null),
            'chiffres' => $list($parsed['chiffres'] ?? null),
            'culture' => $text($parsed['culture'] ?? '') ?: $fallback['culture'],
            'valeurs' => $list($parsed['valeurs'] ?? null),
            'actualites' => $list($parsed['actualites'] ?? null),
            'concurrents' => $list($parsed['concurrents'] ?? null),
            'synthese' => $text($parsed['synthese'] ?? '') ?: $fallback['synthese'],
            'recommandations' => $list($parsed['recommandations'] ?? null) ?: $fallback['recommandations'],
            'questions' => $list($parsed['questions'] ?? null),
        ];
    }

    /** Retire les balises de citation que le modèle laisse parfois dans le texte. */
    private function stripCitations(string $value): string
    {
        return trim((string) preg_replace('/<\/?cite[^>]*>/i', '', $value));
    }
}
