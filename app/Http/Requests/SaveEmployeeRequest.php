<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Enums\JobType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SaveEmployeeRequest',
    required: ['firstName', 'lastName', 'jobTitle', 'contractType', 'hireDate'],
    properties: [
        new OA\Property(property: 'candidatureId', type: 'string', format: 'uuid', nullable: true, description: 'Création : embauche une candidature acceptée'),
        new OA\Property(property: 'matricule', type: 'string', nullable: true, description: 'Généré (EMP-0001…) si absent'),
        new OA\Property(property: 'firstName', type: 'string'),
        new OA\Property(property: 'lastName', type: 'string'),
        new OA\Property(property: 'gender', type: 'string', enum: ['M', 'F'], nullable: true),
        new OA\Property(property: 'birthDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'jobTitle', type: 'string'),
        new OA\Property(property: 'department', type: 'string', nullable: true),
        new OA\Property(property: 'contractType', type: 'string', enum: ['CDI', 'CDD', 'STAGE', 'ALTERNANCE', 'FREELANCE', 'TEMPS_PARTIEL']),
        new OA\Property(property: 'hireDate', type: 'string', format: 'date'),
        new OA\Property(property: 'contractEndDate', type: 'string', format: 'date', nullable: true, description: 'Obligatoire pour un CDD'),
        new OA\Property(property: 'salary', type: 'integer', nullable: true, description: 'Brut mensuel, FCFA'),
        new OA\Property(property: 'annualLeaveDays', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'PROBATION', 'SUSPENDED', 'TERMINATED'], nullable: true),
        new OA\Property(property: 'managerId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'emergencyContactName', type: 'string', nullable: true),
        new OA\Property(property: 'emergencyContactPhone', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
/**
 * Création (POST) et modification (PATCH) d'une fiche employé : mêmes règles,
 * les champs obligatoires devenant facultatifs sur une mise à jour partielle.
 */
class SaveEmployeeRequest extends FormRequest
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
        $companyId = $this->user()?->company_id;
        $employeeId = $this->route('id');

        // Unicité limitée à l'entreprise : deux sociétés peuvent avoir chacune
        // leur EMP-0001.
        $uniqueInCompany = fn (string $column) => Rule::unique('employees', $column)
            ->where('company_id', $companyId)
            ->ignore($employeeId);

        $contractEnd = ['nullable', 'date', 'required_if:contractType,CDD'];
        // `after_or_equal:hireDate` échoue quand hireDate est absent de la
        // requête (mise à jour partielle) : on ne compare que s'il est fourni.
        if ($this->filled('hireDate')) {
            $contractEnd[] = 'after_or_equal:hireDate';
        }

        return [
            // Création uniquement : embauche d'une candidature acceptée. Ignoré
            // sur une mise à jour (l'origine d'une fiche ne change pas).
            'candidatureId' => ['nullable', 'uuid'],
            'matricule' => ['nullable', 'string', 'max:50', $uniqueInCompany('matricule')],
            'firstName' => [$required, 'string', 'max:100'],
            'lastName' => [$required, 'string', 'max:100'],
            'gender' => ['nullable', 'in:M,F'],
            'birthDate' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:255', $uniqueInCompany('email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'jobTitle' => [$required, 'string', 'max:150'],
            'department' => ['nullable', 'string', 'max:100'],
            'contractType' => [$required, Rule::enum(JobType::class)],
            'hireDate' => [$required, 'date'],
            'contractEndDate' => $contractEnd,
            'salary' => ['nullable', 'integer', 'min:0'],
            'annualLeaveDays' => ['nullable', 'integer', 'min:0', 'max:60'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'managerId' => ['nullable', 'uuid'],
            'emergencyContactName' => ['nullable', 'string', 'max:150'],
            'emergencyContactPhone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matricule.unique' => 'Ce matricule est déjà attribué à un autre employé.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre employé.',
            'contractEndDate.required_if' => 'La date de fin est obligatoire pour un CDD.',
            'contractEndDate.after_or_equal' => "La date de fin de contrat doit suivre la date d'embauche.",
            'birthDate.before' => 'La date de naissance doit être passée.',
        ];
    }
}
