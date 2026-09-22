<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePromoCodeRequest extends FormRequest
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
            'influencerId' => ['sometimes', 'nullable', 'uuid', 'exists:influencers,id'],
            'discountType' => ['sometimes', 'nullable', 'string', 'in:PERCENTAGE,FIXED'],
            'discountValue' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'commissionRate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'maxUses' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maxUsesPerUser' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'startsAt' => ['sometimes', 'nullable', 'date'],
            'endsAt' => ['sometimes', 'nullable', 'date'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
