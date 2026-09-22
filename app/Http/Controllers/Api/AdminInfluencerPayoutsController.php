<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInfluencerPayoutRequest;
use App\Http\Requests\UpdateInfluencerPayoutRequest;
use App\Http\Resources\InfluencerPayoutResource;
use App\Models\InfluencerPayout;
use App\Services\InfluencerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdminInfluencerPayoutsController extends Controller
{
    public function __construct(private readonly InfluencerService $influencerService) {}

    public function paginate(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        $paginator = $this->influencerService->paginatePayouts($page, $limit, $request->query('influencerId'));
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (InfluencerPayout $p) => (new InfluencerPayoutResource($p))->resolve())
        );

        return ApiResponse::paginated($paginator);
    }

    public function show(string $id)
    {
        $payout = InfluencerPayout::findOrFail($id);

        return ApiResponse::success((new InfluencerPayoutResource($payout))->resolve());
    }

    public function store(StoreInfluencerPayoutRequest $request)
    {
        try {
            $payout = $this->influencerService->createPayout($request->validated(), $request->user()->id);
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return ApiResponse::success((new InfluencerPayoutResource($payout))->resolve(), status: 201);
    }

    public function update(UpdateInfluencerPayoutRequest $request, string $id)
    {
        $payout = InfluencerPayout::findOrFail($id);
        $payout = $this->influencerService->updatePayout($payout, $request->validated());

        return ApiResponse::success((new InfluencerPayoutResource($payout))->resolve());
    }
}
