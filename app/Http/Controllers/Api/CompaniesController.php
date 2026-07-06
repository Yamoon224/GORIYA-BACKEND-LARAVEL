<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Companies', description: 'Gestion des entreprises')]
class CompaniesController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(private readonly CompanyService $companyService) {}

    /*
    |----------------------------------------------------------------------
    | CREATE — transactionnel : crée la company puis son utilisateur
    | ENTERPRISE associé (voir CompanyService::create)
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/companies',
        tags: ['Companies'],
        summary: "Inscription publique d'une entreprise (crée la company et son utilisateur ENTREPRISE associé)",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/CreateCompanyRequest')
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Entreprise créée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'company', ref: '#/components/schemas/Company'),
                    new OA\Property(property: 'user', type: 'object'),
                    new OA\Property(property: 'accessToken', type: 'string'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreateCompanyRequest $request)
    {
        $result = $this->companyService->create($request->validated(), [
            'logo' => $request->file('logo'),
            'coverImage' => $request->file('coverImage'),
        ]);

        return response()->json([
            'company' => new CompanyResource($result['company']),
            'user' => $result['user']->toArray(), // 'password' est déjà dans $hidden
            'accessToken' => $result['accessToken'],
        ], 201);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ALL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/companies',
        tags: ['Companies'],
        summary: 'Liste complète des entreprises',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des entreprises',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Company'))
            ),
        ]
    )]
    public function index()
    {
        $companies = Company::with('users')->get();

        return CompanyResource::collection($companies);
    }

    /*
    |----------------------------------------------------------------------
    | PAGINATED SEARCH AVEC FILTRES
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/companies/paginate',
        tags: ['Companies'],
        summary: 'Recherche paginée des entreprises avec filtres',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'name', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sector', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'country', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'city', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'companySize', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'email', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'phone', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'website', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE', 'SUSPENDED'])),
            new OA\Parameter(name: 'startDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'endDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Company')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
        ]
    )]
    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 10);

        $paginator = $this->companyService->paginate($page, $limit, [
            'name' => $request->query('name'),
            'sector' => $request->query('sector'),
            'country' => $request->query('country'),
            'city' => $request->query('city'),
            'companySize' => $request->query('companySize'),
            'email' => $request->query('email'),
            'phone' => $request->query('phone'),
            'website' => $request->query('website'),
            'status' => $request->query('status'),
            'startDate' => $request->query('startDate'),
            'endDate' => $request->query('endDate'),
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Company $company) => (new CompanyResource($company))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    /*
    |----------------------------------------------------------------------
    | FIND ONE
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/companies/{id}',
        tags: ['Companies'],
        summary: "Détail d'une entreprise",
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Entreprise trouvée', content: new OA\JsonContent(ref: '#/components/schemas/Company')),
            new OA\Response(response: 404, description: 'Entreprise introuvable'),
        ]
    )]
    public function show(string $id)
    {
        $company = Company::with('users')->find($id);

        if (! $company) {
            abort(404, 'Company not found');
        }

        return new CompanyResource($company);
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/companies/{id}',
        tags: ['Companies'],
        summary: 'Met à jour une entreprise',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/UpdateCompanyRequest')
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Entreprise mise à jour', content: new OA\JsonContent(ref: '#/components/schemas/Company')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Entreprise introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(string $id, UpdateCompanyRequest $request)
    {
        $company = Company::find($id);

        if (! $company) {
            abort(404, 'Company not found');
        }

        $actingUser = $request->user();
        $this->authorizeOwnerOrAdmin(
            $actingUser,
            $actingUser?->role === UserRole::ENTERPRISE && $actingUser->company_id === $company->id
        );

        $updated = $this->companyService->update($company, $request->validated(), [
            'logo' => $request->file('logo'),
            'coverImage' => $request->file('coverImage'),
        ]);

        return new CompanyResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/companies/{id}',
        tags: ['Companies'],
        summary: 'Supprime une entreprise',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Entreprise supprimée', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Company deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Entreprise introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $company = Company::find($id);

        if (! $company) {
            abort(404, 'Company not found');
        }

        $actingUser = $request->user();
        $this->authorizeOwnerOrAdmin(
            $actingUser,
            $actingUser?->role === UserRole::ENTERPRISE && $actingUser->company_id === $company->id
        );

        $this->companyService->remove($company);

        return response()->json(['message' => 'Company deleted successfully']);
    }
}
