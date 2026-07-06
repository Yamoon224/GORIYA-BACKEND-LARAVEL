<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-analytics.controller.ts — pur
 * pass-through vers AnalyticsService, réponses enveloppées via
 * ApiResponse::success. exportReport() garde la divergence déjà établie en
 * Phase 6 : format=pdf ne produit toujours QUE du CSV, mais avec un
 * Content-Type/nom de fichier .pdf trompeurs — contrairement à l'export
 * public (/analytics/export) qui garde toujours .csv quel que soit format.
 * Ces deux comportements distincts sont volontairement préservés tels quels.
 */
#[OA\Tag(name: 'Admin Analytics', description: "Statistiques analytiques agrégées (rôle ADMIN requis)")]
class AdminAnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService) {}

    #[OA\Get(
        path: '/admin/analytics',
        tags: ['Admin Analytics'],
        summary: "Vue d'ensemble analytics (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Analytics agrégées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'analyzedCVs', type: 'integer'),
                        new OA\Property(property: 'successfulInterviews', type: 'integer'),
                        new OA\Property(property: 'matchingRate', type: 'integer', description: 'Pourcentage (0-100)'),
                        new OA\Property(property: 'averageAnalysisTime', type: 'string', example: '2h 30min', description: 'Chaîne littérale côté source, jamais calculée réellement'),
                        new OA\Property(property: 'evolutionData', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'month', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                        ], type: 'object')),
                        new OA\Property(property: 'activityDistribution', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                            new OA\Property(property: 'color', type: 'string', example: '#6366f1'),
                        ], type: 'object')),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index()
    {
        return ApiResponse::success($this->analyticsService->getAnalytics());
    }

    #[OA\Get(
        path: '/admin/analytics/evolution',
        tags: ['Admin Analytics'],
        summary: "Série d'évolution des analyses CV (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', description: "'week' -> 7 jours, 'month' -> 4 semaines, 'year' -> 12 mois, sinon 6 mois par défaut", schema: new OA\Schema(type: 'string', enum: ['week', 'month', 'year'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Série calculée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'month', type: 'string'),
                        new OA\Property(property: 'value', type: 'integer'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function evolution(Request $request)
    {
        return ApiResponse::success($this->analyticsService->getEvolutionData($request->query('period')));
    }

    #[OA\Get(
        path: '/admin/analytics/activity',
        tags: ['Admin Analytics'],
        summary: "Répartition de l'activité par domaine (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Répartition calculée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'value', type: 'integer'),
                        new OA\Property(property: 'color', type: 'string', example: '#6366f1'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function activity()
    {
        return ApiResponse::success($this->analyticsService->getActivityDistribution());
    }

    #[OA\Get(
        path: '/admin/analytics/kpis',
        tags: ['Admin Analytics'],
        summary: 'Indicateurs clés (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'KPIs calculés',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'registrations', type: 'integer'),
                        new OA\Property(property: 'matchingRate', type: 'integer'),
                        new OA\Property(property: 'cvAnalyzed', type: 'integer'),
                        new OA\Property(property: 'interviewsDone', type: 'integer'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function kpis()
    {
        return ApiResponse::success($this->analyticsService->getKPIs());
    }

    /*
    |----------------------------------------------------------------------
    | Réponse brute (pas d'enveloppe ApiResponse::success) : fichier
    | CSV/PDF téléchargeable, cf. la note de classe sur la divergence
    | format=pdf volontairement préservée.
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/analytics/export',
        tags: ['Admin Analytics'],
        summary: 'Export du rapport analytics en CSV (ou pseudo-PDF) (rôle ADMIN requis)',
        description: "format=pdf ne produit toujours QUE du contenu CSV, mais avec un Content-Type/nom de fichier .pdf trompeurs — comportement de la source préservé tel quel.",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['csv', 'pdf'], default: 'csv')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fichier CSV (ou pseudo-PDF) en pièce jointe',
                content: [
                    new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary')),
                    new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
                ]
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function export(Request $request)
    {
        $period = $request->query('period');
        $format = $request->query('format', 'csv');

        $csv = $this->analyticsService->exportReport($period);

        $normalizedFormat = $format === 'pdf' ? 'pdf' : 'csv';
        $filename = "analytics-report-{$period}.{$normalizedFormat}";

        return response($csv, 200)
            ->header('Content-Type', $normalizedFormat === 'pdf' ? 'application/pdf' : 'text/csv; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->header('Content-Length', (string) strlen($csv));
    }
}
