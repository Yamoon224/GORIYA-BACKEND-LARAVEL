<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/dashboard — statistiques agrégées pour le tableau
 * de bord, scopées au rôle de l'appelant (étudiant/entreprise/admin).
 * Purement en lecture, aucune écriture. Logique dans
 * App\Services\DashboardService (partagée avec AdminDashboardController, qui
 * reste global/admin-only).
 */
#[OA\Tag(name: 'Dashboard', description: "Statistiques agrégées pour le tableau de bord, scopées à l'utilisateur courant")]
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    #[OA\Get(
        path: '/dashboard/stats',
        tags: ['Dashboard'],
        summary: 'Statistiques agrégées du tableau de bord',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'start', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'end', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'activeStudents', type: 'integer'),
                    new OA\Property(property: 'partnerCompanies', type: 'integer'),
                    new OA\Property(property: 'analyzedCVs', type: 'integer'),
                    new OA\Property(property: 'jobOffers', type: 'integer'),
                    new OA\Property(property: 'totalApplications', type: 'integer'),
                    new OA\Property(property: 'interviews', type: 'integer'),
                    new OA\Property(property: 'profileViews', type: 'integer', example: 0, description: 'Jamais implémenté côté source — toujours 0'),
                    new OA\Property(property: 'savedJobs', type: 'integer', example: 0, description: 'Jamais implémenté côté source — toujours 0'),
                    new OA\Property(
                        property: 'statsData',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'key', type: 'string'),
                            new OA\Property(property: 'label', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                        ], type: 'object')
                    ),
                    new OA\Property(
                        property: 'chartData',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'month', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                            new OA\Property(property: 'label', type: 'string', nullable: true),
                        ], type: 'object')
                    ),
                    new OA\Property(
                        property: 'lineChartData',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'month', type: 'string'),
                            new OA\Property(property: 'value', type: 'integer'),
                            new OA\Property(property: 'label', type: 'string'),
                        ], type: 'object')
                    ),
                    new OA\Property(
                        property: 'recentCandidates',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'candidateName', type: 'string'),
                            new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
                            new OA\Property(property: 'status', type: 'string'),
                            new OA\Property(property: 'score', type: 'number', nullable: true),
                            new OA\Property(property: 'appliedDate', type: 'string', format: 'date-time'),
                        ], type: 'object')
                    ),
                    new OA\Property(
                        property: 'topOffers',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'applicants', type: 'integer'),
                        ], type: 'object')
                    ),
                    new OA\Property(
                        property: 'recentOffers',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'publishDate', type: 'string', format: 'date-time', nullable: true),
                        ], type: 'object')
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function stats(Request $request)
    {
        return response()->json(
            $this->dashboardService->getStatsForUser($request->user(), $request->query('start'), $request->query('end'))
        );
    }

    #[OA\Get(
        path: '/dashboard/performance',
        tags: ['Dashboard'],
        summary: "Série temporelle du nombre de candidatures ('week', 'month' ou 'year')",
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
                        new OA\Property(property: 'label', type: 'string', nullable: true),
                    ], type: 'object')
                )
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function performance(Request $request)
    {
        return response()->json($this->dashboardService->getPerformanceData($request->query('period')));
    }

    #[OA\Get(
        path: '/dashboard/recent-applications',
        tags: ['Dashboard'],
        summary: 'Candidatures les plus récentes',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 5)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des candidatures',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'candidateName', type: 'string'),
                        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'score', type: 'number', nullable: true),
                        new OA\Property(property: 'appliedDate', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'user', type: 'object', nullable: true, properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'email', type: 'string', format: 'email'),
                        ]),
                        new OA\Property(property: 'jobOffer', type: 'object', nullable: true, properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'title', type: 'string'),
                        ]),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                    ], type: 'object')
                )
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function recentApplications(Request $request)
    {
        return $this->dashboardService->getRecentApplications((int) $request->query('limit', 5), $request->user());
    }

    #[OA\Get(
        path: '/dashboard/recommended-jobs',
        tags: ['Dashboard'],
        summary: "Offres d'emploi actives les plus récentes",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 6)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Liste des offres d'emploi",
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'location', type: 'string', nullable: true),
                        new OA\Property(property: 'type', type: 'string'),
                        new OA\Property(property: 'experience', type: 'string'),
                        new OA\Property(property: 'salary', type: 'string', nullable: true),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'benefits', type: 'string', nullable: true),
                        new OA\Property(property: 'requirements', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'publishDate', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'endDate', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'applicants', type: 'integer'),
                        new OA\Property(property: 'company', type: 'object', nullable: true, properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'name', type: 'string'),
                        ]),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
                    ], type: 'object')
                )
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function recommendedJobs(Request $request)
    {
        return $this->dashboardService->getRecommendedJobs((int) $request->query('limit', 6));
    }

    #[OA\Get(
        path: '/dashboard/profile-views',
        tags: ['Dashboard'],
        summary: "Historique des vues de profil sur N jours (stub — aucun tracking réel n'existe, toujours 0)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer', default: 30)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Vues de profil par jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'views',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'date', type: 'string', format: 'date'),
                            new OA\Property(property: 'count', type: 'integer', example: 0),
                        ], type: 'object')
                    ),
                    new OA\Property(property: 'total', type: 'integer', example: 0),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function profileViews(Request $request)
    {
        return response()->json($this->dashboardService->getProfileViews((int) $request->query('days', 30)));
    }
}
