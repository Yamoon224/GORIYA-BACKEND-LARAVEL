<?php

namespace App\Contracts;

/**
 * Frontière vers le fournisseur d'analyse IA. Implémentée par
 * AnthropicService — permet aux consommateurs (CvAnalysisController,
 * AdminPlatformService) de dépendre d'un contrat plutôt que du SDK Anthropic
 * concret. N'expose que les 3 méthodes réellement consommées ailleurs
 * (extractTextFromBuffer reste un détail d'implémentation interne).
 */
interface AiAnalysisServiceInterface
{
    /**
     * @return array{score: int, strengths: array<int, string>, improvements: array<int, string>, recommendations: array<int, string>}
     */
    public function analyzeCV(string $binary, string $mimeType, string $fileName): array;

    /**
     * @return array{overallScore: int, criteria: array<string, int>, feedback: string}
     */
    public function scoreCandidate(string $candidateName, string $candidateEmail, string $position): array;

    /**
     * @param  array{name: string, email: string}  $candidate
     * @param  array{title: string, company: string, description?: string}  $job
     * @return array{matchingScore: int, matchReasons: array<int, string>}
     */
    public function matchCandidateToJob(array $candidate, array $job): array;
}
