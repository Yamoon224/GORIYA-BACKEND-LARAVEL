<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InfluencerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'defaultCommissionRate' => $this->default_commission_rate !== null ? (float) $this->default_commission_rate : null,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'promoCodesCount' => $this->when(isset($this->promo_codes_count), fn () => (int) $this->promo_codes_count),
            'unpaidBalance' => $this->unpaidBalance(),
            'createdAt' => $this->created_at,
        ];
    }
}
