<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePromoCampaignRequest;
use App\Http\Requests\UpdatePromoCampaignRequest;
use App\Http\Resources\PromoCampaignResource;
use App\Models\PromoCampaign;
use App\Services\PromoCodeService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminPromoCampaignsController extends Controller
{
    public function __construct(private readonly PromoCodeService $promoCodeService) {}

    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->promoCodeService->paginateCampaigns($page, $limit);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (PromoCampaign $c) => (new PromoCampaignResource($c))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    public function stats()
    {
        return ApiResponse::success($this->promoCodeService->stats());
    }

    public function show(string $id)
    {
        $campaign = PromoCampaign::withCount('codes')->findOrFail($id);

        return ApiResponse::success((new PromoCampaignResource($campaign))->resolve());
    }

    public function store(StorePromoCampaignRequest $request)
    {
        $campaign = $this->promoCodeService->createCampaign($request->validated(), $request->user()->id);

        return ApiResponse::success((new PromoCampaignResource($campaign))->resolve(), status: 201);
    }

    public function update(UpdatePromoCampaignRequest $request, string $id)
    {
        $campaign = PromoCampaign::findOrFail($id);
        $campaign = $this->promoCodeService->updateCampaign($campaign, $request->validated());

        return ApiResponse::success((new PromoCampaignResource($campaign))->resolve());
    }

    public function destroy(string $id)
    {
        $campaign = PromoCampaign::findOrFail($id);
        $this->promoCodeService->deleteCampaign($campaign);

        return ApiResponse::success(null);
    }
}
