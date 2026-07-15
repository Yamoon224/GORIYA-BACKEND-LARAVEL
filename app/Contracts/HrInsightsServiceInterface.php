<?php

namespace App\Contracts;

/**
 * Frontière vers l'analyse IA des réponses agrégées à une EmployeeSurvey —
 * implémentée par AnthropicHrInsightsService. Ne reçoit que du texte déjà
 * agrégé/anonymisé (jamais un user_id), par construction de
 * EmployeeSurveyService::stats().
 */
interface HrInsightsServiceInterface
{
    /**
     * @param  array<int, string>  $textAnswers  Réponses libres agrégées, toutes questions confondues
     * @return array{trends: array<int, string>, frictionPoints: array<int, string>, recommendations: array<int, string>}
     */
    public function analyzeSurveyResponses(array $textAnswers): array;
}
