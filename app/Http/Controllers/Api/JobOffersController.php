<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateJobOfferRequest;
use App\Http\Requests\UpdateJobOfferRequest;
use App\Http\Resources\JobOfferResource;
use App\Models\JobOffer;
use App\Services\JobOfferService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Job Offers', description: "Gestion des offres d'emploi")]
class JobOffersController extends Controller
{
    private const RELATIONS = ['company', 'candidatures'];

    public function __construct(private readonly JobOfferService $jobOfferService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/job-offers',
        tags: ['Job Offers'],
        summary: "Crée une offre d'emploi",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateJobOfferRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Offre créée', content: new OA\JsonContent(ref: '#/components/schemas/JobOffer')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateJobOfferRequest $request)
    {
        $jobOffer = $this->jobOfferService->create($request->validated());

        return new JobOfferResource($jobOffer);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL (offres consultables publiquement, sans connexion)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/job-offers',
        tags: ['Job Offers'],
        summary: "Liste complète des offres d'emploi",
        responses: [
            new OA\Response(
                response: 200,
                description: "Liste des offres d'emploi",
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/JobOffer'))
            ),
        ]
    )]
    public function index()
    {
        $jobOffers = JobOffer::with(self::RELATIONS)->get();

        return JobOfferResource::collection($jobOffers);
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES (public)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/job-offers/paginate',
        tags: ['Job Offers'],
        summary: "Recherche paginée des offres d'emploi avec filtres",
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'title', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL'])),
            new OA\Parameter(name: 'salary', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'CLOSED', 'DRAFT'])),
            new OA\Parameter(name: 'companyId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'applicants', in: 'query', schema: new OA\Schema(type: 'integer')),
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
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->jobOfferService->paginate($page, $limit, [
            'title' => $request->query('title'),
            'location' => $request->query('location'),
            'type' => $request->query('type'),
            'salary' => $request->query('salary'),
            'status' => $request->query('status'),
            'companyId' => $request->query('companyId'),
            'applicants' => $request->has('applicants') ? $request->query('applicants') : null,
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (JobOffer $jobOffer) => (new JobOfferResource($jobOffer))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/job-offers/{id}',
        tags: ['Job Offers'],
        summary: "Détail d'une offre d'emploi",
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Offre trouvée', content: new OA\JsonContent(ref: '#/components/schemas/JobOffer')),
            new OA\Response(response: 404, description: 'Offre introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $jobOffer = JobOffer::with(self::RELATIONS)->find($id);

        if (! $jobOffer) {
            abort(404, 'JobOffer not found');
        }

        return new JobOfferResource($jobOffer);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/job-offers/{id}',
        tags: ['Job Offers'],
        summary: "Met à jour une offre d'emploi",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateJobOfferRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Offre mise à jour', content: new OA\JsonContent(ref: '#/components/schemas/JobOffer')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Offre introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdateJobOfferRequest $request)
    {
        $jobOffer = JobOffer::with(self::RELATIONS)->find($id);

        if (! $jobOffer) {
            abort(404, "JobOffer with id {$id} not found");
        }

        $updated = $this->jobOfferService->update($jobOffer, $request->validated());

        return new JobOfferResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/job-offers/{id}',
        tags: ['Job Offers'],
        summary: "Supprime une offre d'emploi",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Offre supprimée', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'JobOffer deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Offre introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $jobOffer = JobOffer::find($id);

        if (! $jobOffer) {
            abort(404, 'JobOffer not found');
        }

        $this->jobOfferService->remove($jobOffer);

        return response()->json(['message' => 'JobOffer deleted successfully']);
    }
}
