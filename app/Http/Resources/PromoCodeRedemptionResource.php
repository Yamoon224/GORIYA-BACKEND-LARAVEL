<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoCodeRedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promoCodeId' => $this->promo_code_id,
            'promoCode' => $this->whenLoaded('promoCode', fn () => $this->promoCode->code),
            'influencerId' => $this->influencer_id,
            'userId' => $this->user_id,
            'userName' => $this->whenLoaded('user', fn () => $this->user?->name),
            'planId' => $this->plan_id,
            'planName' => $this->whenLoaded('plan', fn () => $this->plan?->name),
            'originalAmount' => (float) $this->original_amount,
            'discountAmount' => (float) $this->discount_amount,
            'finalAmount' => (float) $this->final_amount,
            'currency' => $this->currency,
            'commissionRate' => $this->commission_rate !== null ? (float) $this->commission_rate : null,
            'commissionAmount' => $this->commission_amount !== null ? (float) $this->commission_amount : null,
            'status' => $this->status->value,
            'payoutId' => $this->payout_id,
            'redeemedAt' => $this->redeemed_at,
            'createdAt' => $this->created_at,
        ];
    }
}
