<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Enrollment',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'course', ref: '#/components/schemas/Course', nullable: true),
        new OA\Property(property: 'progress', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['IN_PROGRESS', 'COMPLETED']),
        new OA\Property(property: 'hasCertificate', type: 'boolean'),
        new OA\Property(property: 'enrolledAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completedAt', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course' => $this->whenLoaded('course', fn () => new CourseResource($this->course)),
            'progress' => $this->progress,
            'status' => $this->status,
            'hasCertificate' => (bool) $this->certificate_path,
            'enrolledAt' => $this->enrolled_at,
            'completedAt' => $this->completed_at,
        ];
    }
}
