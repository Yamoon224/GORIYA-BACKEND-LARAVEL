<?php

namespace App\Http\Controllers\Api;

use App\Enums\InterviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CvAnalysisResource;
use App\Http\Resources\InterviewSessionResource;
use App\Http\Resources\MatchingResultResource;
use App\Http\Resources\ScoringResultResource;
use App\Models\CvAnalysis;
use App\Models\InterviewSession;
use App\Models\MatchingResult;
use App\Models\ScoringResult;
use App\Services\Admin\AdminActionService;
use App\Services\Admin\AdminReportingService;
use App\Services\CvAnalysisService;
use App\Services\InterviewSessionService;
use App\Services\MatchingResultService;
use App\Services\ScoringResultService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-ai.controller.ts. Vues admin sur le
 * domaine IA/scoring déjà porté (Phases 3-4) — pass-through vers les
 * Services existants + AdminReportingService/AdminActionService pour les
 * stats/actions IA.
 */
#[OA\Tag(name: 'Admin AI', description: 'Analyse CV, simulation d\'entretien, matching et scoring IA (rôle ADMIN requis)')]
class AdminAiController extends Controller
{
    public function __construct(
        private readonly CvAnalysisService $cvAnalysisService,
        private readonly InterviewSessionService $interviewSessionService,
        private readonly MatchingResultService $matchingResultService,
        private readonly ScoringResultService $scoringResultService,
        private readonly AdminReportingService $adminReportingService,
        private readonly AdminActionService $adminActionService,
    ) {}

    /*
    |----------------------------------------------------------------------
    | CV ANALYSIS
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/cv-analysis/stats',
        tags: ['Admin AI'],
        summary: "Statistiques des analyses CV (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques calculées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'totalAnalyzed', type: 'integer'),
                        new OA\Property(property: 'completed', type: 'integer'),
                        new OA\Property(property: 'analyzing', type: 'integer'),
                        new OA\Property(property: 'failed', type: 'integer'),
                        new OA\Property(property: 'averageScore', type: 'integer'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function cvStats()
    {
        return ApiResponse::success($this->adminReportingService->getCvAnalysisStats());
    }

    #[OA\Get(
        path: '/admin/cv-analysis/recent',
        tags: ['Admin AI'],
        summary: 'Liste paginée des analyses CV (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ANALYZING', 'COMPLETED', 'FAILED'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CvAnalysis')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function recentCvAnalysis(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->cvAnalysisService->paginate($page, $limit, [
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (CvAnalysis $cv) => (new CvAnalysisResource($cv))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/cv-analysis/recommendations',
        tags: ['Admin AI'],
        summary: 'Suggestions extraites des analyses CV récentes (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Suggestions (10 max, extraites des 20 analyses les plus récentes)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'category', type: 'string', example: 'CV'),
                        new OA\Property(property: 'suggestion', type: 'string'),
                        new OA\Property(property: 'impact', type: 'string', example: 'medium'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function cvRecommendations()
    {
        return ApiResponse::success($this->adminReportingService->getCvRecommendations());
    }

    #[OA\Get(
        path: '/admin/cv-analysis/{id}',
        tags: ['Admin AI'],
        summary: "Détail d'une analyse CV (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Analyse trouvée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/CvAnalysis'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Analyse introuvable'),
        ]
    )]
    public function showCvAnalysis(string $id)
    {
        $cv = CvAnalysis::find($id);

        if (! $cv) {
            abort(404, 'CVAnalysis not found');
        }

        return ApiResponse::success(new CvAnalysisResource($cv));
    }

    #[OA\Post(
        path: '/admin/cv/analyze',
        tags: ['Admin AI'],
        summary: 'Analyse un CV via le service IA (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(properties: [
                    new OA\Property(property: 'cv', type: 'string', format: 'binary', description: 'Fichier CV à analyser'),
                ], type: 'object')
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Résultat de l\'analyse IA',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'score', type: 'integer'),
                        new OA\Property(property: 'suggestions', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'strengths', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'improvements', type: 'array', items: new OA\Items(type: 'string')),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function analyzeCv(Request $request)
    {
        return ApiResponse::success($this->adminActionService->analyzeCv($request->file('cv')));
    }

    #[OA\Post(
        path: '/admin/cv/upload',
        tags: ['Admin AI'],
        summary: 'Upload un CV vers le stockage public sans analyse (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(properties: [
                    new OA\Property(property: 'cv', type: 'string', format: 'binary', description: 'Fichier CV à uploader'),
                ], type: 'object')
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'URL du fichier stocké',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'cvUrl', type: 'string', example: '/storage/admin/uploads/uuid.pdf'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function uploadCv(Request $request)
    {
        return ApiResponse::success($this->adminActionService->createCvUpload($request->file('cv')));
    }

    #[OA\Delete(
        path: '/admin/cv-analysis/{id}',
        tags: ['Admin AI'],
        summary: 'Supprime une analyse CV (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Analyse supprimée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Analyse introuvable'),
        ]
    )]
    public function destroyCvAnalysis(string $id)
    {
        $cv = CvAnalysis::find($id);

        if (! $cv) {
            abort(404, 'CVAnalysis not found');
        }

        $this->cvAnalysisService->remove($cv);

        return ApiResponse::success(null);
    }

    /*
    |----------------------------------------------------------------------
    | INTERVIEW SIMULATION
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/interview-simulation/stats',
        tags: ['Admin AI'],
        summary: "Statistiques des simulations d'entretien (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques calculées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'todaySessions', type: 'integer'),
                        new OA\Property(property: 'averageScore', type: 'integer'),
                        new OA\Property(property: 'averageDuration', type: 'string', example: '45 min'),
                        new OA\Property(property: 'satisfaction', type: 'integer', description: 'Pourcentage (0-100) de sessions complétées avec un score >= 70'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function interviewStats()
    {
        return ApiResponse::success($this->adminReportingService->getInterviewStats());
    }

    #[OA\Get(
        path: '/admin/interview-simulation/sessions',
        tags: ['Admin AI'],
        summary: "Liste paginée des sessions d'entretien (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'COMPLETED', 'SCHEDULED'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InterviewSession')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function interviewSessions(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->interviewSessionService->paginate($page, $limit, [
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (InterviewSession $session) => (new InterviewSessionResource($session))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/interview-simulation/active',
        tags: ['Admin AI'],
        summary: "Sessions d'entretien actives ou planifiées (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sessions ACTIVE ou SCHEDULED',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InterviewSession')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function activeInterviewSessions()
    {
        return ApiResponse::success($this->adminReportingService->getActiveInterviewSessions());
    }

    /*
    |----------------------------------------------------------------------
    | Pagination en mémoire côté AdminReportingService (pas un paginator
    | Eloquent) — la réponse a déjà la forme {data,meta}, on la renvoie
    | telle quelle plutôt que de la faire transiter par ApiResponse::paginated().
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/interview-simulation/history',
        tags: ['Admin AI'],
        summary: "Historique paginé des entretiens complétés (rôle ADMIN requis)",
        description: "Réponse brute (pas d'enveloppe ApiResponse::success) — pagination en mémoire déjà en forme {data,meta}.",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InterviewSession')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function interviewHistory(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        return response()->json($this->adminReportingService->getInterviewHistory($page, $limit));
    }

    #[OA\Get(
        path: '/admin/interview-simulation/sessions/{id}',
        tags: ['Admin AI'],
        summary: "Détail d'une session d'entretien (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session trouvée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/InterviewSession'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Session introuvable'),
        ]
    )]
    public function showInterviewSession(string $id)
    {
        $session = InterviewSession::find($id);

        if (! $session) {
            abort(404, 'InterviewSession not found');
        }

        return ApiResponse::success(new InterviewSessionResource($session));
    }

    #[OA\Post(
        path: '/admin/interview-simulation/start',
        tags: ['Admin AI'],
        summary: "Démarre une nouvelle session d'entretien (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'candidateId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'position', type: 'string'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session créée (statut ACTIVE)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/InterviewSession'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Candidat introuvable'),
        ]
    )]
    public function startInterview(Request $request)
    {
        return ApiResponse::success(
            $this->adminActionService->createInterviewSimulation($request->input('candidateId'), $request->input('position'))
        );
    }

    #[OA\Patch(
        path: '/admin/interview-simulation/sessions/{sessionId}/end',
        tags: ['Admin AI'],
        summary: "Termine une session d'entretien (statut COMPLETED) (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'feedback', type: 'string'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session mise à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/InterviewSession'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Session introuvable'),
        ]
    )]
    public function endInterview(string $sessionId, Request $request)
    {
        $session = InterviewSession::find($sessionId);

        if (! $session) {
            abort(404, 'InterviewSession not found');
        }

        $updated = $this->interviewSessionService->update($session, [
            'feedback' => $request->input('feedback'),
            'status' => InterviewStatus::COMPLETED->value,
        ]);

        return ApiResponse::success(new InterviewSessionResource($updated));
    }

    #[OA\Delete(
        path: '/admin/interview-simulation/sessions/{id}',
        tags: ['Admin AI'],
        summary: "Supprime une session d'entretien (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session supprimée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Session introuvable'),
        ]
    )]
    public function destroyInterviewSession(string $id)
    {
        $session = InterviewSession::find($id);

        if (! $session) {
            abort(404, 'InterviewSession not found');
        }

        $session->delete();

        return ApiResponse::success(null);
    }

    /*
    |----------------------------------------------------------------------
    | MATCHING
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/matching/stats',
        tags: ['Admin AI'],
        summary: 'Statistiques de matching (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques calculées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'totalMatches', type: 'integer'),
                        new OA\Property(property: 'averageScore', type: 'integer'),
                        new OA\Property(property: 'successRate', type: 'integer', description: 'Pourcentage de matches au statut FINALISE'),
                        new OA\Property(property: 'pendingMatches', type: 'integer'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function matchingStats()
    {
        return ApiResponse::success($this->adminReportingService->getMatchingStats());
    }

    #[OA\Get(
        path: '/admin/matching/recent',
        tags: ['Admin AI'],
        summary: 'Liste paginée des résultats de matching (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['NOUVEAU', 'EN_COURS', 'FINALISE'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MatchingResult')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function recentMatching(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->matchingResultService->paginate($page, $limit, [
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (MatchingResult $result) => (new MatchingResultResource($result))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/matching/algorithms',
        tags: ['Admin AI'],
        summary: 'Performance simulée des algorithmes de matching (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Métriques calculées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'precision', type: 'integer'),
                        new OA\Property(property: 'recall', type: 'integer'),
                        new OA\Property(property: 'f1Score', type: 'integer'),
                        new OA\Property(property: 'algorithms', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'accuracy', type: 'integer'),
                        ], type: 'object')),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function matchingAlgorithms()
    {
        return ApiResponse::success($this->adminReportingService->getMatchingAlgorithms());
    }

    #[OA\Get(
        path: '/admin/matching/activity',
        tags: ['Admin AI'],
        summary: 'Activité récente de matching (10 max) (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Événements récents',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'type', type: 'string', example: 'matching'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function matchingActivity()
    {
        return ApiResponse::success($this->adminReportingService->getMatchingActivity());
    }

    #[OA\Post(
        path: '/admin/matching/trigger',
        tags: ['Admin AI'],
        summary: 'Déclenche un matching IA candidat/offre (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'candidateId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'jobOfferId', type: 'string', format: 'uuid'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Résultat de matching créé (statut NOUVEAU)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/MatchingResult'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Candidat ou offre introuvable'),
        ]
    )]
    public function triggerMatching(Request $request)
    {
        return ApiResponse::success(
            $this->adminActionService->triggerMatching($request->input('candidateId'), $request->input('jobOfferId'))
        );
    }

    #[OA\Patch(
        path: '/admin/matching/{id}/status',
        tags: ['Admin AI'],
        summary: "Met à jour le statut d'un résultat de matching (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['NOUVEAU', 'EN_COURS', 'FINALISE']),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Résultat de matching mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/MatchingResult'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Résultat introuvable'),
        ]
    )]
    public function updateMatchingStatus(string $id, Request $request)
    {
        $result = MatchingResult::find($id);

        if (! $result) {
            abort(404, 'MatchingResult not found');
        }

        $updated = $this->matchingResultService->update($result, ['status' => $request->input('status')]);

        return ApiResponse::success(new MatchingResultResource($updated));
    }

    /*
    |----------------------------------------------------------------------
    | SCORING
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/admin/scoring/stats',
        tags: ['Admin AI'],
        summary: 'Statistiques de scoring (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques calculées',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'generatedScores', type: 'integer'),
                        new OA\Property(property: 'averageScore', type: 'integer'),
                        new OA\Property(property: 'accuracy', type: 'integer', description: 'Pourcentage de résultats au statut COMPLETED'),
                        new OA\Property(property: 'averageTime', type: 'string', example: '3 min', description: 'Chaîne littérale côté source, jamais calculée réellement'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function scoringStats()
    {
        return ApiResponse::success($this->adminReportingService->getScoringStats());
    }

    #[OA\Get(
        path: '/admin/scoring/criteria',
        tags: ['Admin AI'],
        summary: 'Critères de scoring configurés, avec score moyen recalculé (rôle ADMIN requis)',
        description: 'Persisté en Cache (admin:scoring_criteria) — valeur par défaut : Compétences (40%), Expérience (35%), Communication (25%).',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Critères calculés',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'weight', type: 'integer'),
                        new OA\Property(property: 'score', type: 'integer'),
                        new OA\Property(property: 'maxScore', type: 'integer'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function scoringCriteria()
    {
        return ApiResponse::success($this->adminReportingService->getScoringCriteria());
    }

    #[OA\Get(
        path: '/admin/scoring/performance',
        tags: ['Admin AI'],
        summary: 'Performance du scoring dans le temps (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Métriques et tendance mensuelle',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'precision', type: 'integer'),
                        new OA\Property(property: 'recall', type: 'integer'),
                        new OA\Property(property: 'f1Score', type: 'integer'),
                        new OA\Property(property: 'trendData', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'month', type: 'string', example: '2026-07'),
                            new OA\Property(property: 'precision', type: 'integer'),
                            new OA\Property(property: 'recall', type: 'integer'),
                        ], type: 'object')),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function scoringPerformance()
    {
        return ApiResponse::success($this->adminReportingService->getScoringPerformance());
    }

    #[OA\Get(
        path: '/admin/scoring/recent',
        tags: ['Admin AI'],
        summary: 'Liste paginée des résultats de scoring (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['COMPLETED', 'IN_PROGRESS'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ScoringResult')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function recentScoring(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->scoringResultService->paginate($page, $limit, [
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (ScoringResult $result) => (new ScoringResultResource($result))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/scoring/{id}',
        tags: ['Admin AI'],
        summary: "Détail d'un résultat de scoring (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Résultat trouvé',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/ScoringResult'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Résultat introuvable'),
        ]
    )]
    public function showScoringResult(string $id)
    {
        $result = ScoringResult::find($id);

        if (! $result) {
            abort(404, 'ScoringResult not found');
        }

        return ApiResponse::success(new ScoringResultResource($result));
    }

    #[OA\Post(
        path: '/admin/scoring/analyze',
        tags: ['Admin AI'],
        summary: 'Génère un scoring IA pour un candidat/poste (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'candidateId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'position', type: 'string'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Résultat de scoring créé (statut COMPLETED)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/ScoringResult'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Candidat introuvable'),
        ]
    )]
    public function analyzeScoring(Request $request)
    {
        return ApiResponse::success(
            $this->adminActionService->analyzeScoring($request->input('candidateId'), $request->input('position'))
        );
    }

    #[OA\Patch(
        path: '/admin/scoring/criteria',
        tags: ['Admin AI'],
        summary: 'Remplace les critères de scoring configurés (rôle ADMIN requis)',
        description: 'Persiste le tableau `criteria` tel quel en Cache (admin:scoring_criteria), sans validation de structure.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'criteria', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'weight', type: 'integer'),
                    new OA\Property(property: 'score', type: 'integer'),
                    new OA\Property(property: 'maxScore', type: 'integer'),
                ], type: 'object')),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Critères mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'weight', type: 'integer'),
                        new OA\Property(property: 'score', type: 'integer'),
                        new OA\Property(property: 'maxScore', type: 'integer'),
                    ], type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function updateScoringCriteria(Request $request)
    {
        return ApiResponse::success($this->adminReportingService->updateScoringCriteria($request->input('criteria', [])));
    }
}
