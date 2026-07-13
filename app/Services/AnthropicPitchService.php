<?php

namespace App\Services;

use App\Contracts\PitchAiServiceInterface;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Génère et score les pitchs Goriya (texte ou script de pitch vidéo). Comme
 * les autres services Anthropic*, sans ANTHROPIC_API_KEY configurée chaque
 * appel retourne une valeur de repli statique (comportement attendu en dev).
 */
class AnthropicPitchService implements PitchAiServiceInterface
{
    use InteractsWithClaude;

    private const GENERATE_FALLBACK = <<<'TEXT'
Bonjour, je suis un professionnel motivé et déterminé à mettre mes compétences au service de votre organisation. Mon parcours m'a permis de développer une solide capacité d'adaptation et un réel goût pour les défis. Je suis convaincu que mon profil correspond à vos attentes et je serais ravi d'en discuter plus en détail avec vous.
TEXT;

    private const SCORE_FALLBACK = [
        'clarte' => 70,
        'impact' => 65,
        'persuasion' => 65,
        'feedback' => "Pitch correct mais générique — ajoutez des exemples concrets et des résultats chiffrés pour renforcer l'impact.",
    ];

    private const TYPE_LABELS = [
        'EMPLOI' => 'une candidature à un poste',
        'CONCOURS' => 'un concours ou une bourse',
        'APPEL_PROJET' => 'un appel à projets',
        'STARTUP' => 'un pitch de startup devant des investisseurs',
    ];

    public function __construct()
    {
        $this->initClaudeClient();
    }

    public function generate(array $profile, ?array $job, string $type): string
    {
        if (! $this->hasClaudeClient()) {
            return self::GENERATE_FALLBACK;
        }

        try {
            $context = self::TYPE_LABELS[$type] ?? 'une candidature professionnelle';
            $jobBlock = $job
                ? "Poste visé : {$job['title']} chez {$job['company']}".(! empty($job['description']) ? "\nDescription : ".$this->truncateForClaude($job['description'], 800) : '')
                : '';

            $prompt = <<<PROMPT
Vous êtes un coach en communication professionnelle. Rédigez un pitch oral percutant de 45 à 60 secondes (environ 130-160 mots) pour {$context}, à la première personne, prêt à être lu à voix haute (donc sans markdown, sans puces, juste un texte fluide).

Candidat : {$profile['name']}
{$jobBlock}

Le pitch doit être clair, structuré (accroche, valeur ajoutée, appel à l'action) et percutant. Répondez uniquement avec le texte du pitch, en français, sans guillemets ni commentaire.
PROMPT;

            $text = trim($this->requestClaudeText($prompt, 512));

            return $text !== '' ? $text : self::GENERATE_FALLBACK;
        } catch (Throwable $e) {
            Log::error('Pitch generation failed: '.$e->getMessage());

            return self::GENERATE_FALLBACK;
        }
    }

    public function score(string $content): array
    {
        $fallback = self::SCORE_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $prompt = <<<PROMPT
Vous êtes un coach en prise de parole. Évaluez ce pitch professionnel :
---
{$this->truncateForClaude($content, 2000)}
---

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{
  "clarte": <entier entre 0 et 100>,
  "impact": <entier entre 0 et 100>,
  "persuasion": <entier entre 0 et 100>,
  "feedback": "<conseil concret d'amélioration en français, 1-2 phrases>"
}
PROMPT;

            $text = $this->requestClaudeText($prompt, 512);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return [
                'clarte' => max(0, min(100, (int) round((float) ($parsed['clarte'] ?? 70)))),
                'impact' => max(0, min(100, (int) round((float) ($parsed['impact'] ?? 65)))),
                'persuasion' => max(0, min(100, (int) round((float) ($parsed['persuasion'] ?? 65)))),
                'feedback' => (string) ($parsed['feedback'] ?? $fallback['feedback']),
            ];
        } catch (Throwable $e) {
            Log::error('Pitch scoring failed: '.$e->getMessage());

            return $fallback;
        }
    }
}
