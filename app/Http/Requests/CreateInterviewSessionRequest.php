<?php

namespace App\Http\Requests;

use App\Enums\InterviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateInterviewSessionRequest',
    required: ['candidateName', 'candidateEmail', 'position', 'duration', 'status', 'startTime'],
    properties: [
        new OA\Property(property: 'candidateName', type: 'string'),
        new OA\Property(property: 'candidateEmail', type: 'string', format: 'email'),
        new OA\Property(property: 'position', type: 'string'),
        new OA\Property(property: 'duration', type: 'number'),
        new OA\Property(property: 'score', type: 'number', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'COMPLETED', 'SCHEDULED']),
        new OA\Property(property: 'startTime', type: 'string', format: 'date-time'),
        new OA\Property(property: 'feedback', type: 'string', nullable: true),
    ]
)]
class CreateInterviewSessionRequest extends FormRequest
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
            'candidateName' => ['required', 'string'],
            'candidateEmail' => ['required', 'email'],
            'position' => ['required', 'string'],
            'duration' => ['required', 'numeric'],
            'score' => ['nullable', 'numeric'],
            'status' => ['required', Rule::enum(InterviewStatus::class)],
            'startTime' => ['required', 'date'],
            'feedback' => ['nullable', 'string'],
        ];
    }
}
