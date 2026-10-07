<?php

namespace App\Services\Concerns;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\WebSearchResultBlock;
use Anthropic\Messages\WebSearchTool20250305;
use Anthropic\Messages\WebSearchToolResultBlock;
use Closure;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plomberie Claude partagée entre tous les services IA (extrait de
 * AnthropicService, seul consommateur jusqu'ici). Centralise
 * l'initialisation du client, l'appel texte et le parsing JSON défensif
 * pour que chaque nouveau service IA (Research, Pitch, Presentations,
 * Chat...) n'ait pas à les redupliquer.
 *
 * Le client n'est créé qu'au premier appel réel à Claude : ces services sont
 * injectés dans des contrôleurs très sollicités (offres, employés, tableau de
 * bord), où chaque requête payait jusque-là la construction du client et une
 * ligne de journal sans jamais appeler l'IA.
 */
trait InteractsWithClaude
{
    private ?Client $claudeClient = null;

    /** Conservé pour les constructeurs existants : plus rien à préparer d'avance. */
    protected function initClaudeClient(): void {}

    protected function hasClaudeClient(): bool
    {
        return (bool) config('services.anthropic.key');
    }

    /**
     * Client partagé par la requête, avec un délai maximal : sans lui, un appel
     * qui ne répond pas bloquait le processus PHP jusqu'à sa propre limite
     * d'exécution, et le SDK le retentait deux fois.
     */
    private function claude(): Client
    {
        return $this->claudeClient ??= new Client(
            apiKey: (string) config('services.anthropic.key'),
            requestOptions: [
                'transporter' => new HttpClient([
                    'timeout' => (float) config('services.anthropic.timeout', 60),
                    'connect_timeout' => 10,
                ]),
                'maxRetries' => (int) config('services.anthropic.max_retries', 1),
            ],
        );
    }

    private function claudeModel(): string
    {
        return (string) config('services.anthropic.model');
    }

    /**
     * Réutilise une réponse IA déjà obtenue pour exactement les mêmes données
     * (même modèle, même langue). `$fresh` doit renvoyer `null` quand l'IA n'a
     * pas répondu : un repli n'est jamais mis en cache.
     *
     * @template T
     *
     * @param  Closure(): ?T  $fresh
     * @return ?T
     */
    protected function rememberClaudeResult(string $scope, mixed $input, int $hours, Closure $fresh): mixed
    {
        $key = 'ai:'.$scope.':'.hash('sha256', json_encode([$this->claudeModel(), App::getLocale(), $input], JSON_UNESCAPED_UNICODE));

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }
        if ($cached !== null) {
            return $cached;
        }

        $result = $fresh();
        if ($result !== null) {
            try {
                Cache::put($key, $result, now()->addHours($hours));
            } catch (Throwable $e) {
                Log::warning('Cache IA indisponible : '.$e->getMessage());
            }
        }

        return $result;
    }

    protected function requestClaudeText(string $prompt, int $maxTokens): string
    {
        $response = $this->claude()->messages->create(
            maxTokens: $maxTokens,
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $this->claudeModel(),
        );

        foreach ($response->content as $block) {
            if ($block instanceof TextBlock) {
                return $block->text;
            }
        }

        return '';
    }

    /**
     * Variante multi-tour avec system prompt — pour requestClaudeText(), le
     * "prompt" unique suffit (analyse ponctuelle) ; ici l'appelant fournit
     * l'historique complet (GORIYA Chat) et un system prompt distinct, non
     * mélangé aux messages (l'API Messages n'a pas de rôle "system").
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    protected function requestClaudeChat(array $messages, int $maxTokens, ?string $system = null): string
    {
        $response = $this->claude()->messages->create(
            maxTokens: $maxTokens,
            messages: $messages,
            model: $this->claudeModel(),
            system: $system,
        );

        foreach ($response->content as $block) {
            if ($block instanceof TextBlock) {
                return $block->text;
            }
        }

        return '';
    }

    /**
     * Appel texte avec recherche web côté Anthropic (outil serveur
     * `web_search`) : Claude lance lui-même ses requêtes puis rédige sa
     * réponse. Le texte arrive découpé en plusieurs blocs (un par passage
     * cité) : on les recolle. Les pages consultées sont renvoyées à part
     * pour être affichées comme sources.
     *
     * @return array{text: string, sources: list<array{title: string, url: string}>}
     */
    protected function requestClaudeWebResearch(string $prompt, int $maxTokens, int $maxSearches): array
    {
        $response = $this->claude()->messages->create(
            maxTokens: $maxTokens,
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $this->claudeModel(),
            tools: [WebSearchTool20250305::with(maxUses: $maxSearches)],
        );

        $text = '';
        $sources = [];

        foreach ($response->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            } elseif ($block instanceof WebSearchToolResultBlock && is_array($block->content)) {
                foreach ($block->content as $result) {
                    if ($result instanceof WebSearchResultBlock && ! isset($sources[$result->url])) {
                        $sources[$result->url] = ['title' => $result->title, 'url' => $result->url];
                    }
                }
            }
        }

        return ['text' => $text, 'sources' => array_values($sources)];
    }

    protected function truncateForClaude(string $text, int $length): string
    {
        return mb_substr($text, 0, $length);
    }

    /**
     * Instruction de langue à ajouter en fin de prompt — lit
     * App::getLocale(), résolue par requête par le middleware SetLocale.
     * Remplace les "Répondez en français." en dur historiquement présents
     * dans chaque service Anthropic* (V2A/V2B), qui ignoraient la locale de
     * l'utilisateur même quand FR n'était pas sa préférence.
     */
    protected function localizedInstruction(): string
    {
        return match (App::getLocale()) {
            'en' => 'Respond in English.',
            'pt' => 'Responda em português.',
            'ar' => 'أجب باللغة العربية.',
            default => 'Répondez en français.',
        };
    }

    /**
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    protected function parseClaudeJson(string $text, array $fallback): array
    {
        try {
            $cleaned = trim(preg_replace('/```(?:json)?\s*|```/', '', $text));

            if (! preg_match('/\{[\s\S]*\}/', $cleaned, $matches)) {
                return $fallback;
            }

            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : $fallback;
        } catch (Throwable $e) {
            Log::error('JSON parse failed for Claude response: '.mb_substr($text, 0, 200));

            return $fallback;
        }
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    protected function ensureClaudeStringArray(mixed $value, array $fallback): array
    {
        if (! is_array($value) || count($value) === 0) {
            return $fallback;
        }

        return array_values(array_filter(array_map('strval', $value), fn ($item) => $item !== ''));
    }
}
