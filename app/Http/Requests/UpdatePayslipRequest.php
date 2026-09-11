<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdatePayslipRequest',
    required: ['bonuses', 'deductions'],
    properties: [
        new OA\Property(property: 'bonuses', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'amount', type: 'integer'),
            new OA\Property(property: 'taxable', type: 'boolean', description: 'Soumise à l\'impôt (oui par défaut)'),
            new OA\Property(property: 'subjectToContributions', type: 'boolean', description: 'Soumise aux cotisations (oui par défaut)'),
        ])),
        new OA\Property(property: 'deductions', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'amount', type: 'integer'),
        ])),
    ]
)]
/** Primes et retenues saisies sur un bulletin en brouillon : la liste remplace l'existante. */
class UpdatePayslipRequest extends FormRequest
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
            'bonuses' => ['present', 'array', 'max:20'],
            'bonuses.*.label' => ['required', 'string', 'max:100'],
            'bonuses.*.amount' => ['required', 'integer', 'min:1'],
            'bonuses.*.taxable' => ['nullable', 'boolean'],
            'bonuses.*.subjectToContributions' => ['nullable', 'boolean'],
            'deductions' => ['present', 'array', 'max:20'],
            'deductions.*.label' => ['required', 'string', 'max:100'],
            'deductions.*.amount' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bonuses.*.label.required' => 'Chaque prime a besoin d\'un libellé.',
            'bonuses.*.amount.min' => 'Le montant d\'une prime doit être positif.',
            'deductions.*.label.required' => 'Chaque retenue a besoin d\'un libellé.',
            'deductions.*.amount.min' => 'Le montant d\'une retenue doit être positif.',
        ];
    }
}
