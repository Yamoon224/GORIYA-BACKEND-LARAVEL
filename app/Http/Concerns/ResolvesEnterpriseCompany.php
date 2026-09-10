<?php

namespace App\Http\Concerns;

use App\Enums\UserRole;
use Illuminate\Http\Request;

/**
 * Réserve un contrôleur au compte entreprise lui-même.
 *
 * `company_id` seul ne suffit pas : un compte USER peut aussi en porter un
 * (employé répondant à une enquête interne). Sans le contrôle de rôle, ce
 * collaborateur lirait les fiches — salaires compris — de tous ses collègues.
 */
trait ResolvesEnterpriseCompany
{
    protected function enterpriseCompanyId(Request $request): string
    {
        $user = $request->user();

        if (! $user || $user->role !== UserRole::ENTERPRISE || ! $user->company_id) {
            abort(403, 'Réservé aux comptes entreprise.');
        }

        return $user->company_id;
    }
}
