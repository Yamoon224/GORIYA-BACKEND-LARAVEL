<?php

namespace App\Contracts;

/**
 * Frontière vers le fournisseur d'analyse IA utilisé par Pitch Goriya —
 * implémentée par AnthropicPitchService. generate() produit le script (lu
 * tel quel pour un pitch texte, ou servant de prompteur pour un pitch
 * vidéo) ; score() évalue ce même texte, que le pitch soit finalement rendu
 * en texte ou en vidéo (voir ProcessPitchVideoJob pour le cas vidéo).
 */
interface PitchAiServiceInterface
{
    /**
     * @param  array{name: string, email: string, about?: string}  $profile
     * @param  array{title?: string, company?: string, description?: string}|null  $job
     */
    public function generate(array $profile, ?array $job, string $type): string;

    /**
     * @return array{clarte: int, impact: int, persuasion: int, feedback: string}
     */
    public function score(string $content): array;
}
