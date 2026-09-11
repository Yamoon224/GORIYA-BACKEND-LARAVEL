<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportPotentialPartnersRequest;
use App\Http\Requests\StorePotentialPartnerRequest;
use App\Http\Requests\UpdatePotentialPartnerRequest;
use App\Http\Requests\UpdatePotentialPartnerStatusRequest;
use App\Http\Resources\PotentialPartnerResource;
use App\Models\PotentialPartner;
use App\Services\PotentialPartnerImportService;
use App\Services\PotentialPartnerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use RuntimeException;

/**
 * Module Potentiels Partenaires (rôle ADMIN requis) : fiches d'entreprises
 * ciblées pour des campagnes de mailing partenariat. Pas de mirroir NestJS —
 * nouveau module, donc validation par Form Request (contrairement à
 * AdminCompaniesController qui reproduit un body non validé côté legacy).
 */
#[OA\Tag(name: 'Admin Potential Partners', description: 'Gestion des partenaires potentiels côté admin')]
class AdminPotentialPartnersController extends Controller
{
    public function __construct(
        private readonly PotentialPartnerService $partnerService,
        private readonly PotentialPartnerImportService $importService,
    ) {}

    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->partnerService->paginate($page, $limit, [
            'search' => $request->query('search'),
            'sector' => $request->query('sector'),
            'city' => $request->query('city'),
            'companySize' => $request->query('companySize'),
            'status' => $request->query('status'),
            'hasEmail' => $request->has('hasEmail') ? $request->boolean('hasEmail') : null,
        ]);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (PotentialPartner $p) => (new PotentialPartnerResource($p))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    public function stats()
    {
        return ApiResponse::success($this->partnerService->stats());
    }

    public function filters()
    {
        return ApiResponse::success([
            'sectors' => $this->partnerService->sectors(),
            'cities' => $this->partnerService->cities(),
        ]);
    }

    public function show(string $id)
    {
        $partner = PotentialPartner::findOrFail($id);

        return ApiResponse::success((new PotentialPartnerResource($partner))->resolve());
    }

    public function store(StorePotentialPartnerRequest $request)
    {
        $partner = $this->partnerService->create($request->validated());

        return ApiResponse::success((new PotentialPartnerResource($partner))->resolve(), status: 201);
    }

    public function update(UpdatePotentialPartnerRequest $request, string $id)
    {
        $partner = PotentialPartner::findOrFail($id);
        $partner = $this->partnerService->update($partner, $request->validated());

        return ApiResponse::success((new PotentialPartnerResource($partner))->resolve());
    }

    public function updateStatus(UpdatePotentialPartnerStatusRequest $request, string $id)
    {
        $partner = PotentialPartner::findOrFail($id);
        $partner = $this->partnerService->updateStatus($partner, $request->validated()['status']);

        return ApiResponse::success((new PotentialPartnerResource($partner))->resolve());
    }

    public function destroy(string $id)
    {
        $partner = PotentialPartner::findOrFail($id);
        $this->partnerService->delete($partner);

        return ApiResponse::success(null);
    }

    public function import(ImportPotentialPartnersRequest $request)
    {
        $file = $request->file('file');

        try {
            $stats = $this->importService->importFromCsv($file->getRealPath(), 'csv_upload');
        } catch (RuntimeException $e) {
            abort(400, $e->getMessage());
        }

        return ApiResponse::success($stats);
    }
}
