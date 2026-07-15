<?php

namespace App\Services\Concerns;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plomberie Claude partagée entre tous les services IA (extrait de
 * AnthropicService, seul consommateur jusqu'ici). Centralise
 * l'initialisation du client, l'appel texte et le parsing JSON défensif
 * pour que chaque nouveau service IA (Research, Pitch, Presentations,
 * Chat...) n'ait pas à les redupliquer.
 */
trait InteractsWithClaude
{
    private ?Client $claudeClient = null;

    private string $claudeModel;

    protected function initClaudeClient(): void
    {
        $apiKey = config('services.anthropic.key');
        $this->claudeModel = config('services.anthropic.model');

        if ($apiKey) {
            $this->claudeClient = new Client(apiKey: $apiKey);
            Log::info(static::class.' initialized with model '.$this->claudeModel);
        } else {
            Log::warning('ANTHROPIC_API_KEY not set — '.static::class.' will use intelligent fallback values');
        }
    }

    protected function hasClaudeClient(): bool
    {
        return $this->claudeClient !== null;
    }

    protected function requestClaudeText(string $prompt, int $maxTokens): string
    {
        $response = $this->claudeClient->messages->create(
            maxTokens: $maxTokens,
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $this->claudeModel,
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
        $response = $this->claudeClient->messages->create(
            maxTokens: $maxTokens,
            messages: $messages,
            model: $this->claudeModel,
            system: $system,
        );

        foreach ($response->content as $block) {
            if ($block instanceof TextBlock) {
                return $block->text;
            }
        }

        return '';
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
