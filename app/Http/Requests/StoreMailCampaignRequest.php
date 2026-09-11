<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMailCampaignRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:255'],
            'bodyHtml' => ['required', 'string'],
            'targetFilters' => ['nullable', 'array'],
            'targetFilters.search' => ['nullable', 'string'],
            'targetFilters.sector' => ['nullable', 'string'],
            'targetFilters.city' => ['nullable', 'string'],
            'targetFilters.companySize' => ['nullable', 'string'],
            'targetFilters.status' => ['nullable', 'string'],
        ];
    }
}
