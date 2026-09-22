<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaignId' => $this->campaign_id,
            'campaignName' => $this->whenLoaded('campaign', fn () => $this->campaign->name),
            'influencerId' => $this->influencer_id,
            'influencerName' => $this->whenLoaded('influencer', fn () => $this->influencer?->name),
            'code' => $this->code,
            'discountType' => $this->when($this->discount_type !== null, fn () => $this->discount_type->value),
            'discountValue' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'commissionRate' => $this->commission_rate !== null ? (float) $this->commission_rate : null,
            'maxUses' => $this->max_uses,
            'usedCount' => $this->used_count,
            'maxUsesPerUser' => $this->max_uses_per_user,
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at,
        ];
    }
}
