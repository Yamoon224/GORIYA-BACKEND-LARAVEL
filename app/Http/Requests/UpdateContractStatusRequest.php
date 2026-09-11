<?php

namespace App\Http\Requests;

use App\Enums\ContractStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateContractStatusRequest',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'ENDED', 'TERMINATED']),
        new OA\Property(property: 'endDate', type: 'string', format: 'date', nullable: true, description: 'ENDED : date de fin retenue (fin prévue par défaut)'),
        new OA\Property(property: 'terminationDate', type: 'string', format: 'date', nullable: true, description: "TERMINATED : date de rupture (aujourd'hui par défaut)"),
        new OA\Property(property: 'terminationReason', type: 'string', nullable: true, description: 'Obligatoire pour TERMINATED'),
        new OA\Property(property: 'markEmployeeDeparted', type: 'boolean', nullable: true, description: "TERMINATED : passe aussi l'employé en « Parti »"),
    ]
)]
class UpdateContractStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([
                ContractStatus::ACTIVE->value,
                ContractStatus::ENDED->value,
                ContractStatus::TERMINATED->value,
            ])],
            'endDate' => ['nullable', 'date'],
            'terminationDate' => ['nullable', 'date'],
            'terminationReason' => ['nullable', 'string', 'max:2000', 'required_if:status,'.ContractStatus::TERMINATED->value],
            'markEmployeeDeparted' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terminationReason.required_if' => 'Indiquez le motif de la rupture.',
        ];
    }
}
