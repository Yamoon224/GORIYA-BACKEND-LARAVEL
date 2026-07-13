<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AttachPitchVideoRequest',
    required: ['video'],
    properties: [
        new OA\Property(property: 'video', type: 'string', format: 'binary', description: 'MP4/WebM/MOV, max 60s (validé côté frontend) — max 50 Mo'),
    ]
)]
class AttachPitchVideoRequest extends FormRequest
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
            'video' => [
                'required',
                'file',
                'mimetypes:video/mp4,video/webm,video/quicktime',
                'max:51200',
            ],
        ];
    }
}
