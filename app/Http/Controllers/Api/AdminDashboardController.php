<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-dashboard.controller.ts — pur
 * pass-through vers DashboardService, réponses enveloppées via
 * ApiResponse::success (contrairement à DashboardController, qui renvoie du
 * JSON brut).
 */
#[OA\Tag(name: 'Admin Dashboard', description: "Tableau de bord admin — statistiques agrégées de la plateforme (rôle ADMIN requis)")]
class AdminDashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    #[OA\Get(
        path: '/admin/dashboard/stats',
        tags: ['Admin Dashboard'],
        summary: 'Statistiques globales du tableau de bord (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'start', in: 'query', description: 'Début de plage (ISO 8601) — filtre candidatures/offres', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'end', in: 'query', description: 'Fin de plage (ISO 8601)', schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques calculées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'activeStudents', type: 'integer'),
                        new OA\Property(property: 'partnerCompanies', type: 'integer'),
                        new OA\Property(property: 'analyzedCVs', type: 'integer'),
                        new OA\Property(property: 'jobOffers', type: 'integer'),
                        new OA\Property(property: 'totalApplications', type: 'integer'),
                        new OA\Property(property: 'interviews', type: 'integer'),
                        new OA\Property(property: 'profileViews', type: 'integer', description: 'Jamais implémenté côté source — toujours 0'),
                        new OA\Property(property: 'savedJobs', type: 'integer', description: 'Jamais implémenté côté source — toujours 0'),
                        new OA\Property(property: 'statsData', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'key', type: 'string'),
                            new OA\Property(property: 'label', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                        ], type: 'object')),
                        new OA\Property(property: 'chartData', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'month', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                            new OA\Property(property: 'label', type: 'string'),
                        ], type: 'object')),
                        new OA\Property(property: 'lineChartData', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'month', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                            new OA\Property(property: 'label', type: 'string'),
                        ], type: 'object')),
                        new OA\Property(property: 'recentCandidates', type: 'array', items: new OA\Items(ref: '#/components/schemas/Candidature')),
                        new OA\Property(property: 'topOffers', type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer')),
                        new OA\Property(property: 'recentOffers', type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer')),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function stats(Request $request)
    {
        return ApiResponse::success($this->dashboardService->getStats($request->query('start'), $request->query('end')));
    }

    #[OA\Get(
        path: '/admin/dashboard/performance',
        tags: ['Admin Dashboard'],
        summary: 'Série de performance pour graphique (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', description: "'week' -> 7 derniers jours, 'year' -> 12 mois, sinon 6 mois par défaut", schema: new OA\Schema(type: 'string', enum: ['week', 'month', 'year'])),
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
                        new OA\Property(property: 'label', type: 'string', description: "Absent quand period='week'"),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function performance(Request $request)
    {
        return ApiResponse::success($this->dashboardService->getPerformanceData($request->query('period')));
    }

    #[OA\Get(
        path: '/admin/dashboard/recent-applications',
        tags: ['Admin Dashboard'],
        summary: 'Candidatures récentes (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 5)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des candidatures récentes',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Candidature')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function recentApplications(Request $request)
    {
        return ApiResponse::success($this->dashboardService->getRecentApplications((int) $request->query('limit', 5)));
    }

    #[OA\Get(
        path: '/admin/dashboard/recommended-jobs',
        tags: ['Admin Dashboard'],
        summary: 'Offres recommandées (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 6)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des offres recommandées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function recommendedJobs(Request $request)
    {
        return ApiResponse::success($this->dashboardService->getRecommendedJobs((int) $request->query('limit', 6)));
    }

    #[OA\Get(
        path: '/admin/dashboard/profile-views',
        tags: ['Admin Dashboard'],
        summary: 'Série de vues de profil — stub, toujours à zéro (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer', default: 30)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Aucun tracking de vues réel côté source — stub fidèle',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'views', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'date', type: 'string', format: 'date'),
                            new OA\Property(property: 'count', type: 'integer', example: 0),
                        ], type: 'object')),
                        new OA\Property(property: 'total', type: 'integer', example: 0),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function profileViews(Request $request)
    {
        return ApiResponse::success($this->dashboardService->getProfileViews((int) $request->query('days', 30)));
    }
}
