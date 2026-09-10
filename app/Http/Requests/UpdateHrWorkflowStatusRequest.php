<?php

namespace App\Http\Requests;

use App\Enums\HrWorkflowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateHrWorkflowStatusRequest',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['IN_PROGRESS', 'APPROVED', 'REJECTED', 'CANCELLED']),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
    ]
)]
/**
 * Décision sur un congé ou une demande RH. Les transitions autorisées depuis
 * le statut courant sont vérifiées par le service, pas ici.
 */
class UpdateHrWorkflowStatusRequest extends FormRequest
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
            // PENDING n'est jamais une cible : on ne « remet pas en attente ».
            'status' => ['required', Rule::enum(HrWorkflowStatus::class)->except([HrWorkflowStatus::PENDING])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
