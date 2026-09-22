<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInfluencerRequest extends FormRequest
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
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'defaultCommissionRate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
