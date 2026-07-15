<?php

namespace App\Contracts;

/**
 * Frontière vers le fournisseur de génération d'avatar animé (Studio IA) —
 * implémentée par DIdAvatarService (D-ID). Contrairement aux services
 * texte (AiAnalysisServiceInterface, PitchAiServiceInterface...), il n'y a
 * pas de repli plausible pour une génération vidéo : sans clé configurée,
 * l'implémentation doit échouer explicitement plutôt que retourner une
 * valeur factice.
 */
interface AvatarGenerationServiceInterface
{
    /**
     * Crée un rendu vidéo ("talk") à partir d'une photo source et d'un
     * script — retourne l'identifiant du rendu côté fournisseur, à utiliser
     * avec getTalkStatus() pour suivre l'avancement (rendu asynchrone).
     */
    public function createTalk(string $sourcePhotoUrl, string $script): string;

    /**
     * @return array{status: string, resultUrl: ?string}  status normalisé : PENDING|DONE|FAILED
     */
    public function getTalkStatus(string $talkId): array;
}
