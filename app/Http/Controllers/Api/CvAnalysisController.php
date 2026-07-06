<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCvAnalysisRequest;
use App\Http\Requests\UpdateCvAnalysisRequest;
use App\Http\Resources\CvAnalysisResource;
use App\Models\CvAnalysis;
use App\Services\CvAnalysisService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'CV Analysis', description: 'Gestion des analyses de CV')]
class CvAnalysisController extends Controller
{
    public function __construct(private readonly CvAnalysisService $cvAnalysisService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/cv-analysis',
        tags: ['CV Analysis'],
        summary: 'Téléverse un CV et crée son analyse',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/CreateCvAnalysisRequest')
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'CV créé', content: new OA\JsonContent(ref: '#/components/schemas/CvAnalysis')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateCvAnalysisRequest $request)
    {
        $cv = $this->cvAnalysisService->create($request->file('file'));

        return new CvAnalysisResource($cv);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/cv-analysis',
        tags: ['CV Analysis'],
        summary: 'Liste complète des analyses de CV',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des analyses',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CvAnalysis'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index()
    {
        return CvAnalysisResource::collection(CvAnalysis::all());
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/cv-analysis/paginate',
        tags: ['CV Analysis'],
        summary: 'Recherche paginée avec filtres',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'analysisScore', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'recommendations', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'uploadDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
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
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->cvAnalysisService->paginate($page, $limit, [
            'analysisScore' => $request->has('analysisScore') ? $request->query('analysisScore') : null,
            'recommendations' => $request->query('recommendations'),
            'uploadDate' => $request->query('uploadDate'),
            'status' => $request->query('status'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (CvAnalysis $cv) => (new CvAnalysisResource($cv))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/cv-analysis/{id}',
        tags: ['CV Analysis'],
        summary: 'Détail d\'une analyse de CV',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Analyse trouvée', content: new OA\JsonContent(ref: '#/components/schemas/CvAnalysis')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Analyse introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $cv = CvAnalysis::find($id);

        if (! $cv) {
            abort(404, 'CVAnalysis not found');
        }

        return new CvAnalysisResource($cv);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/cv-analysis/{id}',
        tags: ['CV Analysis'],
        summary: 'Met à jour une analyse de CV (remplacement de fichier optionnel)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/UpdateCvAnalysisRequest')
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Analyse mise à jour', content: new OA\JsonContent(ref: '#/components/schemas/CvAnalysis')),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Analyse introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdateCvAnalysisRequest $request)
    {
        $cv = CvAnalysis::find($id);

        if (! $cv) {
            abort(404, 'CVAnalysis not found');
        }

        $updated = $this->cvAnalysisService->update($cv, $request->validated(), $request->file('file'));

        return new CvAnalysisResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/cv-analysis/{id}',
        tags: ['CV Analysis'],
        summary: 'Supprime une analyse de CV',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Analyse supprimée', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'CVAnalysis deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Analyse introuvable'),
        ]
    )]
    public function destroy(string $id)
    {
        $cv = CvAnalysis::find($id);

        if (! $cv) {
            abort(404, 'CVAnalysis not found');
        }

        $this->cvAnalysisService->remove($cv);

        return response()->json(['message' => 'CVAnalysis deleted successfully']);
    }
}
