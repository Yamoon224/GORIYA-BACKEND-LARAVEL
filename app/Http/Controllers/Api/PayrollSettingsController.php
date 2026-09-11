<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePayrollSettingsRequest;
use App\Services\PayrollSettingsService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Payroll', description: 'Services RH — paie : paramètres, périodes et bulletins')]
class PayrollSettingsController extends Controller
{
    use ResolvesEnterpriseCompany;

    public function __construct(private readonly PayrollSettingsService $settings) {}

    #[OA\Get(
        path: '/payroll/settings',
        tags: ['Payroll'],
        summary: 'Paramètres de paie (valeurs par défaut Côte d\'Ivoire tant que rien n\'est modifié)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Paramètres'),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function show(Request $request)
    {
        return response()->json($this->settings->forCompany($this->enterpriseCompanyId($request)));
    }

    #[OA\Put(
        path: '/payroll/settings',
        tags: ['Payroll'],
        summary: 'Remplace les cotisations et le barème d\'impôt',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdatePayrollSettingsRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Paramètres enregistrés'),
            new OA\Response(response: 400, description: 'Barème ou cotisations invalides'),
        ]
    )]
    public function update(UpdatePayrollSettingsRequest $request)
    {
        $companyId = $this->enterpriseCompanyId($request);

        return response()->json($this->settings->update($companyId, $request->user(), $request->validated()));
    }

    #[OA\Post(
        path: '/payroll/settings/reset',
        tags: ['Payroll'],
        summary: 'Rétablit les valeurs par défaut',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Paramètres par défaut')]
    )]
    public function reset(Request $request)
    {
        return response()->json($this->settings->reset($this->enterpriseCompanyId($request)));
    }
}
