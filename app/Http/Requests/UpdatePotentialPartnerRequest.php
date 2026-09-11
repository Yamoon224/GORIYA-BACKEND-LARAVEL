<?php

namespace App\Http\Requests;

use App\Enums\PotentialPartnerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePotentialPartnerRequest extends FormRequest
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
        $partnerId = $this->route('id');

        return [
            'ncc' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('potential_partners', 'ncc')->ignore($partnerId)],
            'companyName' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contactName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contactPhone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:255'],
            'activityLabel' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commune' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'companySize' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::enum(PotentialPartnerStatus::class)],
        ];
    }
}
