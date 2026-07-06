<?php

namespace App\Http\Requests;

use App\Enums\CVStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateCvAnalysisRequest',
    properties: [
        new OA\Property(property: 'file', type: 'string', format: 'binary', nullable: true, description: 'application/pdf, .doc ou .docx'),
        new OA\Property(property: 'analysisScore', type: 'number', nullable: true),
        new OA\Property(property: 'recommendations', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'uploadDate', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ANALYZING', 'COMPLETED', 'FAILED'], nullable: true),
    ]
)]
class UpdateCvAnalysisRequest extends FormRequest
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
                'nullable',
                'file',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'analysisScore' => ['nullable', 'numeric'],
            'recommendations' => ['nullable', 'array'],
            'recommendations.*' => ['string'],
            'uploadDate' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(CVStatus::class)],
        ];
    }
}
