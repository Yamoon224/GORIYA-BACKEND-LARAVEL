<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateWebhookRequest',
    required: ['url', 'events'],
    properties: [
        new OA\Property(property: 'url', type: 'string', format: 'uri'),
        new OA\Property(
            property: 'events',
            type: 'array',
            items: new OA\Items(type: 'string', enum: ['candidature.status_updated', 'candidate_assessment.completed'])
        ),
    ]
)]
class CreateWebhookRequest extends FormRequest
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
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'in:candidature.status_updated,candidate_assessment.completed'],
        ];
    }
}
