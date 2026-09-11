<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdatePayrollSettingsRequest',
    required: ['contributions', 'taxBrackets', 'taxLabel'],
    properties: [
        new OA\Property(property: 'contributions', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'employeeRate', type: 'number', nullable: true, description: 'En %'),
            new OA\Property(property: 'employerRate', type: 'number', nullable: true, description: 'En %'),
            new OA\Property(property: 'ceiling', type: 'integer', nullable: true, description: 'Plafond mensuel de la base, FCFA'),
            new OA\Property(property: 'employeeFixed', type: 'integer', nullable: true, description: 'Forfait mensuel salarié, FCFA'),
            new OA\Property(property: 'employerFixed', type: 'integer', nullable: true, description: 'Forfait mensuel employeur, FCFA'),
        ])),
        new OA\Property(property: 'taxBrackets', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'upTo', type: 'integer', nullable: true, description: 'Plafond mensuel ; null pour la dernière tranche'),
            new OA\Property(property: 'rate', type: 'number', description: 'En %'),
        ])),
        new OA\Property(property: 'taxLabel', type: 'string', example: 'ITS'),
        new OA\Property(property: 'taxableAbatementPercent', type: 'number', nullable: true),
        new OA\Property(property: 'deductEmployeeContributions', type: 'boolean', nullable: true),
    ]
)]
class UpdatePayrollSettingsRequest extends FormRequest
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
            'contributions' => ['present', 'array', 'max:20'],
            'contributions.*.code' => ['required', 'string', 'max:30', 'distinct'],
            'contributions.*.label' => ['required', 'string', 'max:100'],
            'contributions.*.employeeRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contributions.*.employerRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contributions.*.ceiling' => ['nullable', 'integer', 'min:1'],
            'contributions.*.employeeFixed' => ['nullable', 'integer', 'min:0'],
            'contributions.*.employerFixed' => ['nullable', 'integer', 'min:0'],
            'taxBrackets' => ['required', 'array', 'min:1', 'max:15'],
            'taxBrackets.*.upTo' => ['nullable', 'integer', 'min:1'],
            'taxBrackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'taxLabel' => ['required', 'string', 'max:30'],
            'taxableAbatementPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deductEmployeeContributions' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contributions.*.code.distinct' => 'Deux cotisations ne peuvent pas porter le même code.',
            'taxBrackets.*.rate.max' => "Le taux d'une tranche ne peut pas dépasser 100 %.",
            'contributions.*.employeeRate.max' => 'Un taux de cotisation ne peut pas dépasser 100 %.',
            'contributions.*.employerRate.max' => 'Un taux de cotisation ne peut pas dépasser 100 %.',
        ];
    }
}
