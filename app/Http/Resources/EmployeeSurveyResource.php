<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EmployeeSurvey',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(
            property: 'questions',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'string'),
                new OA\Property(property: 'question', type: 'string'),
                new OA\Property(property: 'type', type: 'string', enum: ['RATING', 'TEXT']),
            ])
        ),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'ACTIVE', 'CLOSED']),
        new OA\Property(property: 'dueDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'department', type: 'string', nullable: true),
        new OA\Property(property: 'hasResponses', type: 'boolean', description: 'Au moins une réponse reçue — les questions ne sont alors plus modifiables.'),
        new OA\Property(property: 'answered', type: 'boolean', description: "L'employé consultant a déjà répondu — présent seulement sur /me/employee/evaluations, non signifiant côté entreprise."),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]
)]
class EmployeeSurveyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'questions' => $this->questions,
            'status' => $this->status,
            'dueDate' => $this->due_date?->toDateString(),
            'department' => $this->department,
            // `withCount('responses')` évite un aller-retour par évaluation ;
            // repli sur une requête directe si jamais absent (fiabilité > coût).
            'hasResponses' => (int) ($this->responses_count ?? $this->responses()->count()) > 0,
            // Fixé par MyEmployeeController::evaluations() (attribut ad hoc, pas
            // en base) — absent ailleurs, donc toujours `false` par défaut.
            'answered' => (bool) ($this->answered ?? false),
            'createdAt' => $this->created_at,
        ];
    }
}
