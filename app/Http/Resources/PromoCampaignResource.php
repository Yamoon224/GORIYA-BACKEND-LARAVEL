<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'discountType' => $this->discount_type->value,
            'discountValue' => (float) $this->discount_value,
            'applicableUserTypes' => $this->applicable_user_types,
            'applicablePlanIds' => $this->applicable_plan_ids,
            'minPlanPrice' => $this->min_plan_price !== null ? (float) $this->min_plan_price : null,
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
            'status' => $this->status->value,
            'codesCount' => $this->when(isset($this->codes_count), fn () => (int) $this->codes_count),
            'createdAt' => $this->created_at,
        ];
    }
}
