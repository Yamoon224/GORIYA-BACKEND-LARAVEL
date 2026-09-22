<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePromoCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'discountType' => ['sometimes', 'string', 'in:PERCENTAGE,FIXED'],
            'discountValue' => ['sometimes', 'numeric', 'min:0.01'],
            'applicableUserTypes' => ['sometimes', 'array', 'min:1'],
            'applicableUserTypes.*' => ['string', 'in:USER,ENTREPRISE'],
            'applicablePlanIds' => ['sometimes', 'nullable', 'array'],
            'applicablePlanIds.*' => ['uuid'],
            'minPlanPrice' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'startsAt' => ['sometimes', 'nullable', 'date'],
            'endsAt' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:DRAFT,ACTIVE,PAUSED,ENDED'],
        ];
    }
}
