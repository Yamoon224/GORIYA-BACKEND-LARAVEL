<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidatePromoCodeRequest;
use App\Models\SubscriptionPlan;
use App\Services\PromoCodeService;
use App\Support\ApiResponse;

/**
 * Endpoint public d'aperçu d'un code promo, utilisé pour un affichage live
 * côté standard/entreprise si besoin. `checkout()` (SubscriptionService)
 * revalide et recalcule toujours le code lui-même — cet endpoint ne fait
 * jamais foi pour le montant réellement facturé.
 */
class PromoCodesController extends Controller
{
    public function __construct(private readonly PromoCodeService $promoCodeService) {}

    public function validateCode(ValidatePromoCodeRequest $request)
    {
        $data = $request->validated();
        $plan = SubscriptionPlan::findOrFail($data['planId']);
        $userId = $request->user()->id;

        $validation = $this->promoCodeService->validate($data['code'], $userId, $plan);
        $baseAmount = (float) $plan->price;
        $computed = $this->promoCodeService->computeDiscount($validation['code'], $baseAmount);

        return ApiResponse::success([
            'valid' => true,
            'discountType' => $validation['discountType'],
            'discountValue' => $validation['discountValue'],
            'originalAmount' => $baseAmount,
            'discountAmount' => $computed['discountAmount'],
            'finalAmount' => $computed['finalAmount'],
        ]);
    }
}
