<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CandidatureResource;
use App\Http\Resources\JobOfferResource;
use App\Models\Candidature;
use App\Models\JobOffer;
use App\Services\Admin\AdminActionService;
use App\Services\Admin\AdminReportingService;
use App\Services\BookmarkService;
use App\Services\CandidatureService;
use App\Services\JobOfferService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/admin/admin-jobs.controller.ts. Couvre à la fois
 * /admin/job-offers/* et /admin/candidatures/* (même préfixe `/admin` bare
 * côté NestJS). Les écritures ne passent pas par un Form Request — parité
 * avec le body `Record<string, unknown>` non validé.
 */
#[OA\Tag(name: 'Admin Jobs & Candidatures', description: "Gestion des offres d'emploi et des candidatures côté admin")]
class AdminJobsController extends Controller
{
    public function __construct(
        private readonly JobOfferService $jobOfferService,
        private readonly CandidatureService $candidatureService,
        private readonly AdminReportingService $adminReportingService,
        private readonly BookmarkService $bookmarkService,
        private readonly AdminActionService $adminActionService,
    ) {}

    /**
     * Seule la première valeur est retenue quand plusieurs sont fournies —
     * quirk réel de la source (`asArray(value)[0]`), copié tel quel.
     */
    private function asArray(mixed $value): array
    {
        if (! $value) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    #[OA\Get(
        path: '/admin/job-offers/paginate',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Recherche paginée des offres d'emploi avec filtres (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'jobType', in: 'query', description: 'Filtre type — seule la première valeur est retenue si un tableau est fourni', schema: new OA\Schema(type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL'])),
            new OA\Parameter(name: 'salary', in: 'query', description: 'Seule la première valeur est retenue si un tableau est fourni', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'CLOSED', 'DRAFT'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function paginateJobOffers(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->jobOfferService->paginate($page, $limit, [
            'title' => $request->query('search'),
            'location' => $request->query('location'),
            'type' => $this->asArray($request->query('jobType'))[0] ?? null,
            'salary' => $this->asArray($request->query('salary'))[0] ?? null,
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (JobOffer $jobOffer) => (new JobOfferResource($jobOffer))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/job-offers/stats',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Statistiques des offres d'emploi (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'active', type: 'integer'),
                            new OA\Property(property: 'closed', type: 'integer'),
                            new OA\Property(property: 'draft', type: 'integer'),
                            new OA\Property(property: 'totalApplicants', type: 'integer'),
                        ],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function jobStats()
    {
        return ApiResponse::success($this->adminReportingService->getJobOfferStats());
    }

    #[OA\Get(
        path: '/admin/job-offers/sectors',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Répartition des offres d'emploi par secteur d'entreprise (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Répartition par secteur',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        type: 'array',
                        items: new OA\Items(properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'percentage', type: 'integer'),
                        ], type: 'object')
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function jobSectors()
    {
        return ApiResponse::success($this->adminReportingService->getJobOfferSectors());
    }

    #[OA\Post(
        path: '/admin/job-offers',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Crée une offre d'emploi (rôle ADMIN requis, aucune validation FormRequest)",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'location', 'type', 'experience', 'salary', 'description', 'benefits', 'requirements', 'publishDate', 'endDate', 'companyId'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'location', type: 'string'),
                    new OA\Property(property: 'type', type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL']),
                    new OA\Property(property: 'experience', type: 'string', enum: ['JUNIOR', 'INTERMEDIAIRE', 'SENIOR', 'EXPERT']),
                    new OA\Property(property: 'salary', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'benefits', type: 'string'),
                    new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'CLOSED', 'DRAFT'], nullable: true),
                    new OA\Property(property: 'publishDate', type: 'string', format: 'date'),
                    new OA\Property(property: 'endDate', type: 'string', format: 'date'),
                    new OA\Property(property: 'applicants', type: 'integer', nullable: true),
                    new OA\Property(property: 'companyId', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Offre d'emploi créée",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/JobOffer'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function storeJobOffer(Request $request)
    {
        $jobOffer = $this->jobOfferService->create($request->all());

        return ApiResponse::success(new JobOfferResource($jobOffer));
    }

    /*
    |----------------------------------------------------------------------
    | Utilise l'id de l'admin actuellement authentifié comme candidat —
    | comportement littéral de la source (odd mais volontaire, pas une
    | vraie candidature d'un tiers).
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/admin/job-offers/{jobId}/apply',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Postule à une offre d'emploi au nom de l'admin courant (rôle ADMIN requis — comportement littéral de la source, pas une vraie candidature d'un tiers)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'jobId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Candidature créée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Candidature'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Candidat ou offre introuvable'),
        ]
    )]
    public function applyToJob(string $jobId, Request $request)
    {
        return ApiResponse::success($this->adminActionService->createJobApplication($request->user()->id, $jobId));
    }

    #[OA\Post(
        path: '/admin/job-offers/{jobId}/save',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Enregistre une offre d'emploi dans les favoris de l'admin courant (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'jobId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Offre enregistrée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function saveJob(string $jobId, Request $request)
    {
        $this->bookmarkService->saveJob($jobId, $request->user()->id);

        return ApiResponse::success(null);
    }

    #[OA\Delete(
        path: '/admin/job-offers/{jobId}/save',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Retire une offre d'emploi des favoris de l'admin courant (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'jobId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Offre retirée des favoris',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function unsaveJob(string $jobId, Request $request)
    {
        $this->bookmarkService->unsaveJob($jobId, $request->user()->id);

        return ApiResponse::success(null);
    }

    #[OA\Get(
        path: '/me/saved-jobs',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Liste des identifiants d'offres sauvegardées par l'utilisateur courant",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Identifiants des offres sauvegardées",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [new OA\Property(property: 'jobIds', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function savedJobsList(Request $request)
    {
        return ApiResponse::success(['jobIds' => $this->bookmarkService->savedJobIds($request->user()->id)]);
    }

    #[OA\Get(
        path: '/admin/job-offers/{id}',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Détail d'une offre d'emploi (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: "Offre d'emploi trouvée",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/JobOffer'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'JobOffer introuvable'),
        ]
    )]
    public function showJobOffer(string $id)
    {
        $jobOffer = JobOffer::with(['company', 'candidatures'])->find($id);

        if (! $jobOffer) {
            abort(404, 'JobOffer not found');
        }

        return ApiResponse::success(new JobOfferResource($jobOffer));
    }

    #[OA\Patch(
        path: '/admin/job-offers/{id}',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Met à jour une offre d'emploi (rôle ADMIN requis, aucune validation FormRequest)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'title', type: 'string', nullable: true),
                new OA\Property(property: 'location', type: 'string', nullable: true),
                new OA\Property(property: 'type', type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL'], nullable: true),
                new OA\Property(property: 'experience', type: 'string', enum: ['JUNIOR', 'INTERMEDIAIRE', 'SENIOR', 'EXPERT'], nullable: true),
                new OA\Property(property: 'salary', type: 'string', nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'benefits', type: 'string', nullable: true),
                new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'CLOSED', 'DRAFT'], nullable: true),
                new OA\Property(property: 'publishDate', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'endDate', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'applicants', type: 'integer', nullable: true),
                new OA\Property(property: 'companyId', type: 'string', format: 'uuid', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Offre d'emploi mise à jour",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/JobOffer'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'JobOffer introuvable'),
        ]
    )]
    public function updateJobOffer(string $id, Request $request)
    {
        $jobOffer = JobOffer::find($id);

        if (! $jobOffer) {
            abort(404, "JobOffer with id {$id} not found");
        }

        $updated = $this->jobOfferService->update($jobOffer, $request->all());

        return ApiResponse::success(new JobOfferResource($updated));
    }

    #[OA\Patch(
        path: '/admin/job-offers/{id}/status',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Met à jour le statut d'une offre d'emploi (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'CLOSED', 'DRAFT'])]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statut mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/JobOffer'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'JobOffer introuvable'),
        ]
    )]
    public function updateJobStatus(string $id, Request $request)
    {
        $jobOffer = JobOffer::find($id);

        if (! $jobOffer) {
            abort(404, "JobOffer with id {$id} not found");
        }

        $updated = $this->jobOfferService->update($jobOffer, ['status' => $request->input('status')]);

        return ApiResponse::success(new JobOfferResource($updated));
    }

    #[OA\Delete(
        path: '/admin/job-offers/{id}',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Supprime une offre d'emploi (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: "Offre d'emploi supprimée",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'JobOffer introuvable'),
        ]
    )]
    public function destroyJobOffer(string $id)
    {
        $jobOffer = JobOffer::find($id);

        if (! $jobOffer) {
            abort(404, 'JobOffer not found');
        }

        $this->jobOfferService->remove($jobOffer);

        return ApiResponse::success(null);
    }

    #[OA\Get(
        path: '/admin/candidatures/paginate',
        tags: ['Admin Jobs & Candidatures'],
        summary: 'Recherche paginée des candidatures avec filtres (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['EN_ATTENTE', 'EN_COURS', 'APPROUVEE', 'REJETEE'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Candidature')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function paginateCandidatures(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->candidatureService->paginate($page, $limit, [
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Candidature $candidature) => (new CandidatureResource($candidature))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    #[OA\Get(
        path: '/admin/candidatures/stats',
        tags: ['Admin Jobs & Candidatures'],
        summary: 'Statistiques des candidatures (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'enAttente', type: 'integer'),
                            new OA\Property(property: 'enCours', type: 'integer'),
                            new OA\Property(property: 'approuvees', type: 'integer'),
                            new OA\Property(property: 'rejetees', type: 'integer'),
                        ],
                        type: 'object'
                    ),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function candidatureStats()
    {
        return ApiResponse::success($this->adminReportingService->getCandidatureStats());
    }

    #[OA\Get(
        path: '/admin/candidatures/{id}',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Détail d'une candidature (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Candidature trouvée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Candidature'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function showCandidature(string $id)
    {
        $candidature = Candidature::with(['user', 'jobOffer'])->find($id);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        return ApiResponse::success(new CandidatureResource($candidature));
    }

    #[OA\Patch(
        path: '/admin/candidatures/{id}/status',
        tags: ['Admin Jobs & Candidatures'],
        summary: "Met à jour le statut d'une candidature (rôle ADMIN requis)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [new OA\Property(property: 'status', type: 'string', enum: ['EN_ATTENTE', 'EN_COURS', 'APPROUVEE', 'REJETEE'])]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statut mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Candidature'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function updateCandidatureStatus(string $id, Request $request)
    {
        $candidature = Candidature::find($id);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        $updated = $this->candidatureService->update($candidature, ['status' => $request->input('status')]);

        return ApiResponse::success(new CandidatureResource($updated));
    }

    #[OA\Delete(
        path: '/admin/candidatures/{id}',
        tags: ['Admin Jobs & Candidatures'],
        summary: 'Supprime une candidature (rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Candidature supprimée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
            new OA\Response(response: 404, description: 'Candidature introuvable'),
        ]
    )]
    public function destroyCandidature(string $id)
    {
        $candidature = Candidature::find($id);

        if (! $candidature) {
            abort(404, 'Candidature not found');
        }

        $this->candidatureService->remove($candidature);

        return ApiResponse::success(null);
    }
}
