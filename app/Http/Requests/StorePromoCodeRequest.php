<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePromoCodeRequest extends FormRequest
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
            'campaignId' => ['required', 'uuid', 'exists:promo_campaigns,id'],
            'influencerId' => ['nullable', 'uuid', 'exists:influencers,id'],
            'code' => ['required', 'string', 'max:40', 'unique:promo_codes,code'],
            'discountType' => ['nullable', 'string', 'in:PERCENTAGE,FIXED'],
            'discountValue' => ['nullable', 'numeric', 'min:0.01'],
            'commissionRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'maxUses' => ['nullable', 'integer', 'min:1'],
            'maxUsesPerUser' => ['nullable', 'integer', 'min:1'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
