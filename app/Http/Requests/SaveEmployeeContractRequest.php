<?php

namespace App\Http\Requests;

use App\Enums\ContractKind;
use App\Enums\ContractStatus;
use App\Enums\JobType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SaveEmployeeContractRequest',
    required: ['type', 'jobTitle', 'startDate'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['INITIAL', 'RENEWAL', 'AMENDMENT'], nullable: true, description: 'Création uniquement'),
        new OA\Property(property: 'parentId', type: 'string', format: 'uuid', nullable: true, description: 'Contrat renouvelé ou modifié (création uniquement)'),
        new OA\Property(property: 'type', type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL']),
        new OA\Property(property: 'jobTitle', type: 'string'),
        new OA\Property(property: 'startDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date', nullable: true, description: 'Obligatoire pour CDD, stage et alternance'),
        new OA\Property(property: 'trialEndDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'salary', type: 'integer', nullable: true),
        new OA\Property(property: 'weeklyHours', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'ACTIVE'], nullable: true, description: 'Création uniquement'),
        new OA\Property(property: 'signedAt', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
/**
 * Création (POST) et modification (PATCH) d'un contrat. Seules les règles de
 * forme sont ici ; la cohérence avec l'existant (date de fin exigée selon le
 * type, période d'essai dans le contrat, contrat modifiable ou non) est vérifiée
 * par EmployeeContractService, qui connaît les valeurs déjà enregistrées.
 */
class SaveEmployeeContractRequest extends FormRequest
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
        $isUpdate = $this->isMethod('PATCH');
        $required = $isUpdate ? 'sometimes' : 'required';
        $creationOnly = $isUpdate ? 'prohibited' : 'nullable';
        // Comparaison à startDate seulement s'il est fourni (mise à jour partielle).
        $afterStart = $this->filled('startDate') ? ['after_or_equal:startDate'] : [];

        return [
            'kind' => [$creationOnly, Rule::enum(ContractKind::class)],
            'parentId' => [$creationOnly, 'uuid'],
            'type' => [$required, Rule::enum(JobType::class)],
            'jobTitle' => [$required, 'string', 'max:150'],
            'startDate' => [$required, 'date'],
            'endDate' => array_merge(['nullable', 'date'], $afterStart),
            'trialEndDate' => array_merge(['nullable', 'date'], $afterStart),
            'salary' => ['nullable', 'integer', 'min:0'],
            'weeklyHours' => ['nullable', 'integer', 'min:1', 'max:60'],
            'status' => [$creationOnly, Rule::in([ContractStatus::DRAFT->value, ContractStatus::ACTIVE->value])],
            'signedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endDate.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'trialEndDate.after_or_equal' => "La fin de la période d'essai doit suivre le début du contrat.",
            'status.prohibited' => 'Le statut se change par les actions dédiées : mise en vigueur, clôture ou rupture.',
            'kind.prohibited' => "La nature d'un contrat ne change pas après sa création.",
            'parentId.prohibited' => "Le contrat d'origine ne change pas après la création.",
        ];
    }
}
