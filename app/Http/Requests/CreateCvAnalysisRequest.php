<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateCvAnalysisRequest',
    required: ['file'],
    properties: [
        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'application/pdf, .doc ou .docx'),
    ]
)]
class CreateCvAnalysisRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ];
    }
}
