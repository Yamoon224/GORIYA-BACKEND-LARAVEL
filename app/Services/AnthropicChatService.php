<?php

namespace App\Services;

use App\Contracts\ChatAiServiceInterface;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Répond aux messages de GORIYA Chat en tenant compte de l'historique du
 * fil et d'un contexte utilisateur léger. Comme les autres services
 * Anthropic*, sans ANTHROPIC_API_KEY configurée chaque appel retourne une
 * valeur de repli statique (comportement attendu en dev).
 */
class AnthropicChatService implements ChatAiServiceInterface
{
    use InteractsWithClaude;

    private const REPLY_FALLBACK = "Je suis actuellement en mode limité (aucune clé IA configurée), mais je reste à votre écoute. Pouvez-vous préciser votre question sur votre carrière, votre CV ou votre recherche d'emploi ?";

    public function __construct()
    {
        $this->initClaudeClient();
    }

    public function reply(array $history, array $context): string
    {
        if (! $this->hasClaudeClient()) {
            return self::REPLY_FALLBACK;
        }

        try {
            $system = $this->buildSystemPrompt($context);
            $messages = array_map(
                fn (array $message) => [
                    'role' => $message['role'] === 'ASSISTANT' ? 'assistant' : 'user',
                    'content' => $message['content'],
                ],
                $history,
            );

            $text = trim($this->requestClaudeChat($messages, 1024, $system));

            return $text !== '' ? $text : self::REPLY_FALLBACK;
        } catch (Throwable $e) {
            Log::error('Chat reply failed: '.$e->getMessage());

            return self::REPLY_FALLBACK;
        }
    }

    /**
     * @param  array{name: string, lastJobOfferTitle?: string, lastPitchType?: string, skills?: array<int, string>}  $context
     */
    private function buildSystemPrompt(array $context): string
    {
        $facts = ["Prénom de l'utilisateur : {$context['name']}"];

        if (! empty($context['lastJobOfferTitle'])) {
            $facts[] = "Dernier poste auquel il/elle a postulé : {$context['lastJobOfferTitle']}";
        }
        if (! empty($context['lastPitchType'])) {
            $facts[] = "Type de pitch le plus récent créé : {$context['lastPitchType']}";
        }
        if (! empty($context['skills'])) {
            $facts[] = 'Compétences déclarées : '.implode(', ', $context['skills']);
        }

        $factsBlock = implode("\n- ", $facts);

        return <<<SYSTEM
Vous êtes GORIYA Chat, un coach carrière IA bienveillant, performant et inclusif, intégré à la plateforme GORIYA (emploi et développement professionnel en Afrique francophone).

Contexte connu sur l'utilisateur :
- {$factsBlock}

Répondez de façon claire, actionnable et encourageante, sans jargon. Restez concis (quelques phrases, pas d'essai). {$this->localizedInstruction()} Si la question sort du champ carrière/emploi/CV/formation, recentrez poliment la conversation sur ces sujets.
SYSTEM;
    }
}
