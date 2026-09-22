<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InfluencerPayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'influencerId' => $this->influencer_id,
            'influencerName' => $this->whenLoaded('influencer', fn () => $this->influencer->name),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'paymentMethod' => $this->payment_method,
            'paymentReference' => $this->payment_reference,
            'notes' => $this->notes,
            'paidAt' => $this->paid_at,
            'createdAt' => $this->created_at,
        ];
    }
}
