<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInfluencerPayoutRequest extends FormRequest
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
            'influencerId' => ['required', 'uuid', 'exists:influencers,id'],
            'redemptionIds' => ['required', 'array', 'min:1'],
            'redemptionIds.*' => ['uuid'],
            'paymentMethod' => ['nullable', 'string', 'max:100'],
            'paymentReference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'markPaid' => ['nullable', 'boolean'],
        ];
    }
}
