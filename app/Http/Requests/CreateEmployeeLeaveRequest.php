<?php

namespace App\Http\Requests;

use App\Enums\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateEmployeeLeaveRequest',
    required: ['type', 'startDate', 'endDate'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['PAID', 'SICK', 'MATERNITY', 'PATERNITY', 'FAMILY_EVENT', 'UNPAID', 'OTHER']),
        new OA\Property(property: 'startDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ]
)]
class CreateEmployeeLeaveRequest extends FormRequest
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
            'type' => ['required', Rule::enum(LeaveType::class)],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endDate.after_or_equal' => 'La date de fin doit suivre la date de début.',
        ];
    }
}
