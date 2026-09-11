<?php

namespace App\Http\Requests;

use App\Enums\PotentialPartnerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePotentialPartnerRequest extends FormRequest
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
            'ncc' => ['nullable', 'string', 'max:50', 'unique:potential_partners,ncc'],
            'companyName' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'sector' => ['nullable', 'string', 'max:255'],
            'activityLabel' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'commune' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'companySize' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(PotentialPartnerStatus::class)],
        ];
    }
}
