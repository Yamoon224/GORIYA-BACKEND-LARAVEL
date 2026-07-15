<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CandidateAssessment',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'candidatureId', type: 'string', format: 'uuid'),
        new OA\Property(property: 'technicalScore', type: 'integer', nullable: true),
        new OA\Property(property: 'softSkillsScore', type: 'integer', nullable: true),
        new OA\Property(property: 'culturalFitScore', type: 'integer', nullable: true),
        new OA\Property(property: 'overallScore', type: 'integer', nullable: true),
        new OA\Property(
            property: 'skillsTest',
            type: 'array',
            nullable: true,
            items: new OA\Items(properties: [
                new OA\Property(property: 'question', type: 'string'),
                new OA\Property(property: 'type', type: 'string', enum: ['TECHNIQUE', 'COMPORTEMENTAL']),
            ])
        ),
        new OA\Property(property: 'softSkillsFeedback', type: 'string', nullable: true),
        new OA\Property(property: 'reportPath', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['PENDING', 'COMPLETED', 'FAILED']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class CandidateAssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'candidatureId' => $this->candidature_id,
            'technicalScore' => $this->technical_score,
            'softSkillsScore' => $this->soft_skills_score,
            'culturalFitScore' => $this->cultural_fit_score,
            'overallScore' => $this->overall_score,
            'skillsTest' => $this->skills_test,
            'softSkillsFeedback' => $this->soft_skills_feedback,
            'reportPath' => $this->report_path,
            'status' => $this->status,
            'createdAt' => $this->created_at,
        ];
    }
}
