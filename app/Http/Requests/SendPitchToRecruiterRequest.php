<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SendPitchToRecruiterRequest',
    required: ['jobOfferId'],
    properties: [
        new OA\Property(property: 'jobOfferId', type: 'string', format: 'uuid'),
    ]
)]
class SendPitchToRecruiterRequest extends FormRequest
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
            'jobOfferId' => ['required', 'uuid', 'exists:job_offers,id'],
        ];
    }
}
