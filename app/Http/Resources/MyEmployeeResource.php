<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Vue « espace employé » de la fiche : reprend EmployeeResource mais retire
 * `salary` et `notes` — le salaire et les notes RH internes n'ont pas à
 * transiter vers le compte candidat de l'employé (voir la légende du champ
 * Notes dans le formulaire entreprise : « non visibles par l'employé »).
 */
#[OA\Schema(
    schema: 'MyEmployee',
    description: "Fiche employé telle que vue par l'employé lui-même (espace standard) — sans salaire ni notes RH internes.",
)]
class MyEmployeeResource extends EmployeeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['salary'], $data['notes']);

        $data['companyName'] = $this->whenLoaded('company', fn () => $this->company?->name);

        return $data;
    }
}
