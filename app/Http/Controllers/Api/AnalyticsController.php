<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/analytics — statistiques agrégées admin. Purement
 * en lecture, aucune écriture. Logique dans App\Services\AnalyticsService
 * (partagée avec AdminAnalyticsController).
 */
#[OA\Tag(name: 'Analytics', description: 'Statistiques agrégées admin (Rôle ADMIN requis)')]
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService) {}

    #[OA\Get(
        path: '/analytics',
        tags: ['Analytics'],
        summary: "Vue d'ensemble des statistiques analytics (Rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Vue d'ensemble",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'analyzedCVs', type: 'integer'),
                    new OA\Property(property: 'successfulInterviews', type: 'integer'),
                    new OA\Property(property: 'matchingRate', type: 'integer'),
                    new OA\Property(property: 'averageAnalysisTime', type: 'string', example: '2h 30min', description: 'Valeur littérale, jamais calculée côté source'),
                    new OA\Property(
                        property: 'evolutionData',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'month', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                        ], type: 'object')
                    ),
                    new OA\Property(
                        property: 'activityDistribution',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                            new OA\Property(property: 'color', type: 'string', example: '#6366f1'),
                        ], type: 'object')
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function index()
    {
        return response()->json($this->analyticsService->getAnalytics());
    }

    #[OA\Get(
        path: '/analytics/evolution',
        tags: ['Analytics'],
        summary: "Évolution du nombre d'analyses de CV ('week', 'month' ou 'year', Rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string', enum: ['week', 'month', 'year'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Points de la série',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'month', type: 'string'),
                        new OA\Property(property: 'value', type: 'integer'),
                    ], type: 'object')
                )
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function evolution(Request $request)
    {
        return response()->json($this->analyticsService->getEvolutionData($request->query('period')));
    }

    #[OA\Get(
        path: '/analytics/activity',
        tags: ['Analytics'],
        summary: "Répartition de l'activité par type (Rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Répartition de l'activité",
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'value', type: 'integer'),
                        new OA\Property(property: 'color', type: 'string', example: '#6366f1'),
                    ], type: 'object')
                )
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function activity()
    {
        return response()->json($this->analyticsService->getActivityDistribution());
    }

    #[OA\Get(
        path: '/analytics/kpis',
        tags: ['Analytics'],
        summary: 'Indicateurs clés de performance (Rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'KPIs',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'registrations', type: 'integer'),
                    new OA\Property(property: 'matchingRate', type: 'integer'),
                    new OA\Property(property: 'cvAnalyzed', type: 'integer'),
                    new OA\Property(property: 'interviewsDone', type: 'integer'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function kpis()
    {
        return response()->json($this->analyticsService->getKPIs());
    }

    #[OA\Get(
        path: '/analytics/monthly-activity',
        tags: ['Analytics'],
        summary: 'CVs analysés et entretiens réalisés par mois sur les N derniers mois (Rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'months', in: 'query', schema: new OA\Schema(type: 'integer', default: 6))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Activité mensuelle',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'month', type: 'string'),
                    new OA\Property(property: 'cv', type: 'integer'),
                    new OA\Property(property: 'entretiens', type: 'integer'),
                ], type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function monthlyActivity(Request $request)
    {
        return response()->json($this->analyticsService->getMonthlyActivity((int) $request->query('months', 6)));
    }

    #[OA\Get(
        path: '/analytics/user-distribution',
        tags: ['Analytics'],
        summary: 'Répartition des utilisateurs par rôle (Rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Répartition',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'value', type: 'integer'),
                    new OA\Property(property: 'color', type: 'string', example: '#6366f1'),
                ], type: 'object'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function userDistribution()
    {
        return response()->json($this->analyticsService->getUserTypeDistribution());
    }

    #[OA\Get(
        path: '/analytics/export',
        tags: ['Analytics'],
        summary: "Exporte un rapport analytics au format CSV (le format 'pdf' produit aussi un fichier .csv — quirk réel de la source, Rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', default: 'csv')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fichier CSV en pièce jointe',
                content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function export(Request $request)
    {
        $period = $request->query('period');
        $format = $request->query('format', 'csv');

        $csv = $this->analyticsService->exportReport($period);

        // 'pdf' produit quand même un fichier .csv — quirk réel de la
        // source, pas une génération PDF à construire.
        $extension = $format === 'pdf' ? 'csv' : $format;
        $filename = "analytics-report-{$period}.{$extension}";

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->header('Content-Length', (string) strlen($csv));
    }
}
