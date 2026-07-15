<?php

namespace App\Contracts;

/**
 * Frontière vers le fournisseur d'analyse IA utilisé par GORIYA Chat —
 * implémentée par AnthropicChatService.
 */
interface ChatAiServiceInterface
{
    /**
     * @param  array<int, array{role: string, content: string}>  $history  Historique complet du fil, du plus ancien au plus récent (dernier message = celui de l'utilisateur auquel répondre)
     * @param  array{name: string, lastJobOfferTitle?: string, lastPitchType?: string, skills?: array<int, string>}  $context  Contexte utilisateur léger pour personnaliser la réponse
     */
    public function reply(array $history, array $context): string;
}
