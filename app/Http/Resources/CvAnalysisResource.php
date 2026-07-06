<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/cv-analysis/dto/cv-analysis.vm.ts.
 */
#[OA\Schema(
    schema: 'CvAnalysis',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'fileName', type: 'string'),
        new OA\Property(property: 'analysisScore', type: 'number', nullable: true),
        new OA\Property(property: 'recommendations', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'uploadDate', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string', enum: ['ANALYZING', 'COMPLETED', 'FAILED']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class CvAnalysisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fileName' => $this->filename,
            'analysisScore' => $this->analysis_score,
            'recommendations' => $this->recommendations,
            'uploadDate' => $this->upload_date,
            'status' => $this->status->value,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
