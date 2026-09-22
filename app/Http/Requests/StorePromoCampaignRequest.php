<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePromoCampaignRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discountType' => ['required', 'string', 'in:PERCENTAGE,FIXED'],
            'discountValue' => ['required', 'numeric', 'min:0.01'],
            'applicableUserTypes' => ['required', 'array', 'min:1'],
            'applicableUserTypes.*' => ['string', 'in:USER,ENTREPRISE'],
            'applicablePlanIds' => ['nullable', 'array'],
            'applicablePlanIds.*' => ['uuid'],
            'minPlanPrice' => ['nullable', 'numeric', 'min:0'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'status' => ['nullable', 'string', 'in:DRAFT,ACTIVE,PAUSED,ENDED'],
        ];
    }
}
