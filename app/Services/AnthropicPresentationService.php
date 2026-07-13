<?php

namespace App\Services;

use App\Contracts\PresentationAiServiceInterface;
use App\Enums\PresentationType;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Génère la structure logique (pas de rendu visuel) d'un jeu de slides ou
 * d'un schéma à partir d'un brief. Comme les autres services Anthropic*,
 * sans ANTHROPIC_API_KEY configurée chaque appel retourne une valeur de
 * repli statique (comportement attendu en dev).
 */
class AnthropicPresentationService implements PresentationAiServiceInterface
{
    use InteractsWithClaude;

    private const SLIDES_FALLBACK = [
        'slides' => [
            ['title' => 'Introduction', 'bullets' => ['Contexte et objectifs du projet']],
            ['title' => 'Approche', 'bullets' => ['Méthodologie envisagée', 'Étapes clés']],
            ['title' => 'Conclusion', 'bullets' => ['Bénéfices attendus', 'Prochaines étapes']],
        ],
    ];

    private const SCHEMA_FALLBACK = [
        'nodes' => [
            ['id' => 'n1', 'label' => 'Point de départ'],
            ['id' => 'n2', 'label' => 'Étape intermédiaire'],
            ['id' => 'n3', 'label' => 'Résultat'],
        ],
        'edges' => [
            ['from' => 'n1', 'to' => 'n2'],
            ['from' => 'n2', 'to' => 'n3'],
        ],
    ];

    public function __construct()
    {
        $this->initClaudeClient();
    }

    public function generate(string $brief, string $type): array
    {
        return $type === PresentationType::SCHEMA->value
            ? $this->generateSchema($brief)
            : $this->generateSlides($brief);
    }

    private function generateSlides(string $brief): array
    {
        $fallback = self::SLIDES_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $prompt = <<<PROMPT
Vous êtes un consultant spécialisé en présentations professionnelles. À partir du brief suivant, structurez une présentation de 5 à 8 slides.

Brief : {$this->truncateForClaude($brief, 1500)}

Retournez UNIQUEMENT un objet JSON valide (sans markdown) avec exactement cette structure :
{
  "slides": [
    {"title": "<titre de la slide>", "bullets": ["<puce 1>", "<puce 2>", "<puce 3>"]}
  ]
}

Répondez en français. 5 à 8 slides, 2 à 4 puces courtes et concrètes par slide.
PROMPT;

            $text = $this->requestClaudeText($prompt, 1536);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return ['slides' => $this->sanitizeSlides($parsed['slides'] ?? null, $fallback['slides'])];
        } catch (Throwable $e) {
            Log::error('Presentation slides generation failed: '.$e->getMessage());

            return $fallback;
        }
    }

    private function generateSchema(string $brief): array
    {
        $fallback = self::SCHEMA_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $prompt = <<<PROMPT
Vous êtes un consultant spécialisé en schématisation de projets. À partir du brief suivant, structurez un schéma (organigramme, mind map, diagramme de flux ou roadmap selon ce qui convient le mieux).

Brief : {$this->truncateForClaude($brief, 1500)}

Retournez UNIQUEMENT un objet JSON valide (sans markdown) avec exactement cette structure :
{
  "nodes": [{"id": "<identifiant court unique>", "label": "<texte du nœud>"}],
  "edges": [{"from": "<id nœud source>", "to": "<id nœud cible>", "label": "<texte optionnel du lien>"}]
}

Répondez en français. 4 à 10 nœuds, chaque edge doit référencer des id présents dans nodes.
PROMPT;

            $text = $this->requestClaudeText($prompt, 1536);
            $parsed = $this->parseClaudeJson($text, $fallback);

            $nodes = $this->sanitizeNodes($parsed['nodes'] ?? null, $fallback['nodes']);
            $validIds = array_column($nodes, 'id');

            return [
                'nodes' => $nodes,
                'edges' => $this->sanitizeEdges($parsed['edges'] ?? null, $validIds),
            ];
        } catch (Throwable $e) {
            Log::error('Presentation schema generation failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /**
     * @param  array<int, array{title: string, bullets: array<int, string>}>  $fallback
     * @return array<int, array{title: string, bullets: array<int, string>}>
     */
    private function sanitizeSlides(mixed $value, array $fallback): array
    {
        if (! is_array($value) || count($value) === 0) {
            return $fallback;
        }

        $slides = [];
        foreach ($value as $slide) {
            if (! is_array($slide) || empty($slide['title'])) {
                continue;
            }

            $slides[] = [
                'title' => (string) $slide['title'],
                'bullets' => $this->ensureClaudeStringArray($slide['bullets'] ?? null, []),
            ];
        }

        return $slides !== [] ? $slides : $fallback;
    }

    /**
     * @param  array<int, array{id: string, label: string}>  $fallback
     * @return array<int, array{id: string, label: string}>
     */
    private function sanitizeNodes(mixed $value, array $fallback): array
    {
        if (! is_array($value) || count($value) === 0) {
            return $fallback;
        }

        $nodes = [];
        foreach ($value as $node) {
            if (! is_array($node) || empty($node['id']) || empty($node['label'])) {
                continue;
            }

            $nodes[] = ['id' => (string) $node['id'], 'label' => (string) $node['label']];
        }

        return $nodes !== [] ? $nodes : $fallback;
    }

    /**
     * @param  array<int, string>  $validIds
     * @return array<int, array{from: string, to: string, label?: string}>
     */
    private function sanitizeEdges(mixed $value, array $validIds): array
    {
        if (! is_array($value)) {
            return [];
        }

        $edges = [];
        foreach ($value as $edge) {
            if (! is_array($edge) || empty($edge['from']) || empty($edge['to'])) {
                continue;
            }

            if (! in_array($edge['from'], $validIds, true) || ! in_array($edge['to'], $validIds, true)) {
                continue;
            }

            $mapped = ['from' => (string) $edge['from'], 'to' => (string) $edge['to']];
            if (! empty($edge['label'])) {
                $mapped['label'] = (string) $edge['label'];
            }

            $edges[] = $mapped;
        }

        return $edges;
    }
}
