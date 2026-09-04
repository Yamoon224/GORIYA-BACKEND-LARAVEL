<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Corps du wizard de candidature (standard/components/apply-wizard). Tout est
 * facultatif au niveau du schéma : les questions obligatoires de l'offre sont
 * vérifiées dans AdminActionService::createJobApplication, qui seul connaît la
 * configuration de l'offre visée.
 */
#[OA\Schema(
    schema: 'ApplyToJobRequest',
    properties: [
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'coverLetter', type: 'string', nullable: true),
        new OA\Property(property: 'resumeId', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(
            property: 'answers',
            type: 'array',
            nullable: true,
            items: new OA\Items(properties: [
                new OA\Property(property: 'questionId', type: 'string', format: 'uuid'),
                new OA\Property(
                    property: 'value',
                    description: 'Chaîne, booléen, nombre, ou tableau de chaînes pour MULTI_CHOICE',
                    nullable: true
                ),
            ], type: 'object')
        ),
    ]
)]
class ApplyToJobRequest extends FormRequest
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
            'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'coverLetter' => ['nullable', 'string', 'max:5000'],
            'resumeId' => ['nullable', 'uuid'],
            'answers' => ['nullable', 'array'],
            'answers.*.questionId' => ['required', 'uuid'],
            // `value` accepte chaîne / nombre / booléen / tableau selon le type
            // de question : le contrôle fin se fait contre la question réelle.
            'answers.*.value' => ['nullable'],
        ];
    }
}
