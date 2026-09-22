<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInfluencerRequest;
use App\Http\Requests\UpdateInfluencerRequest;
use App\Http\Resources\InfluencerResource;
use App\Http\Resources\PromoCodeRedemptionResource;
use App\Models\Influencer;
use App\Models\PromoCodeRedemption;
use App\Services\InfluencerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminInfluencersController extends Controller
{
    public function __construct(private readonly InfluencerService $influencerService) {}

    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->influencerService->paginate($page, $limit);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Influencer $i) => (new InfluencerResource($i))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    public function show(string $id)
    {
        $influencer = Influencer::findOrFail($id);

        return ApiResponse::success((new InfluencerResource($influencer))->resolve());
    }

    public function store(StoreInfluencerRequest $request)
    {
        $influencer = $this->influencerService->create($request->validated(), $request->user()->id);

        return ApiResponse::success((new InfluencerResource($influencer))->resolve(), status: 201);
    }

    public function update(UpdateInfluencerRequest $request, string $id)
    {
        $influencer = Influencer::findOrFail($id);
        $influencer = $this->influencerService->update($influencer, $request->validated());

        return ApiResponse::success((new InfluencerResource($influencer))->resolve());
    }

    public function destroy(string $id)
    {
        $influencer = Influencer::findOrFail($id);
        $this->influencerService->delete($influencer);

        return ApiResponse::success(null);
    }

    public function balance(string $id)
    {
        $influencer = Influencer::findOrFail($id);

        return ApiResponse::success($this->influencerService->balance($influencer));
    }

    public function redemptions(Request $request, string $id)
    {
        $influencer = Influencer::findOrFail($id);
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->influencerService->paginateRedemptions($influencer, $page, $limit);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (PromoCodeRedemption $r) => (new PromoCodeRedemptionResource($r))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }
}
