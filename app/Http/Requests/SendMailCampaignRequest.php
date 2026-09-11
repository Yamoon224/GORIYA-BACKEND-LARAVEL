<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMailCampaignRequest extends FormRequest
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
            'search' => ['nullable', 'string'],
            'sector' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'companySize' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ];
    }
}
