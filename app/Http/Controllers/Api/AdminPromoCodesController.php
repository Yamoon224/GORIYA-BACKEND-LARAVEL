<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePromoCodeRequest;
use App\Http\Requests\UpdatePromoCodeRequest;
use App\Http\Resources\PromoCodeRedemptionResource;
use App\Http\Resources\PromoCodeResource;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Services\PromoCodeService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminPromoCodesController extends Controller
{
    public function __construct(private readonly PromoCodeService $promoCodeService) {}

    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->promoCodeService->paginateCodes($page, $limit, [
            'campaignId' => $request->query('campaignId'),
            'influencerId' => $request->query('influencerId'),
            'isActive' => $request->has('isActive') ? $request->boolean('isActive') : null,
        ]);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (PromoCode $c) => (new PromoCodeResource($c))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    public function generate()
    {
        return ApiResponse::success(['code' => $this->promoCodeService->generateUniqueCode()]);
    }

    public function show(string $id)
    {
        $code = PromoCode::with(['campaign', 'influencer'])->findOrFail($id);

        return ApiResponse::success((new PromoCodeResource($code))->resolve());
    }

    public function store(StorePromoCodeRequest $request)
    {
        $code = $this->promoCodeService->createCode($request->validated(), $request->user()->id);

        return ApiResponse::success((new PromoCodeResource($code))->resolve(), status: 201);
    }

    public function update(UpdatePromoCodeRequest $request, string $id)
    {
        $code = PromoCode::findOrFail($id);
        $code = $this->promoCodeService->updateCode($code, $request->validated());

        return ApiResponse::success((new PromoCodeResource($code))->resolve());
    }

    public function destroy(string $id)
    {
        $code = PromoCode::findOrFail($id);
        $this->promoCodeService->deleteCode($code);

        return ApiResponse::success(null);
    }

    public function redemptions(Request $request, string $id)
    {
        $code = PromoCode::findOrFail($id);
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->promoCodeService->paginateRedemptionsForCode($code, $page, $limit);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (PromoCodeRedemption $r) => (new PromoCodeRedemptionResource($r))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }
}
