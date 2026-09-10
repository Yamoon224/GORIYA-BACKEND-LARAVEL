<?php

namespace App\Http\Requests;

use App\Enums\HrRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateHrRequestRequest',
    required: ['type', 'subject'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['WORK_CERTIFICATE', 'SALARY_CERTIFICATE', 'SALARY_ADVANCE', 'TRAINING', 'EQUIPMENT', 'SCHEDULE_CHANGE', 'OTHER']),
        new OA\Property(property: 'subject', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'integer', nullable: true, description: 'FCFA — obligatoire pour SALARY_ADVANCE'),
    ]
)]
class CreateHrRequestRequest extends FormRequest
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
            'type' => ['required', Rule::enum(HrRequestType::class)],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amount' => ['nullable', 'integer', 'min:1', 'required_if:type,'.HrRequestType::SALARY_ADVANCE->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required_if' => "Indiquez le montant de l'avance demandée.",
        ];
    }
}
