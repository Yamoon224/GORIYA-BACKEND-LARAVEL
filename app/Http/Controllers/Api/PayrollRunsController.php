<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesEnterpriseCompany;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollRunResource;
use App\Models\PayrollRun;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PayrollRunsController extends Controller
{
    use ResolvesEnterpriseCompany;

    public function __construct(private readonly PayrollService $payroll) {}

    #[OA\Get(
        path: '/payroll/runs',
        tags: ['Payroll'],
        summary: 'Périodes de paie, de la plus récente à la plus ancienne',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Périodes', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollRun'))),
            new OA\Response(response: 403, description: 'Réservé aux comptes entreprise'),
        ]
    )]
    public function index(Request $request)
    {
        return PayrollRunResource::collection($this->payroll->listRuns($this->enterpriseCompanyId($request)));
    }

    #[OA\Post(
        path: '/payroll/runs',
        tags: ['Payroll'],
        summary: 'Prépare la paie d\'un mois (brouillon calculé)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year', 'month'], properties: [
            new OA\Property(property: 'year', type: 'integer', example: 2026),
            new OA\Property(property: 'month', type: 'integer', example: 9),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Période créée', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRun')),
            new OA\Response(response: 400, description: 'Période déjà existante, brouillon en cours ou ordre non respecté'),
        ]
    )]
    public function store(Request $request)
    {
        $companyId = $this->enterpriseCompanyId($request);
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $run = $this->payroll->createRun($companyId, $request->user(), (int) $data['year'], (int) $data['month']);

        return (new PayrollRunResource($run))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/payroll/runs/{id}',
        tags: ['Payroll'],
        summary: 'Période de paie avec ses bulletins',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Période', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRun')),
            new OA\Response(response: 404, description: 'Période introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        return new PayrollRunResource($this->runOrFail($id, $request));
    }

    #[OA\Post(
        path: '/payroll/runs/{id}/recompute',
        tags: ['Payroll'],
        summary: 'Recalcule un brouillon avec les données du jour',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Période recalculée', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRun')),
            new OA\Response(response: 400, description: 'Paie déjà validée'),
        ]
    )]
    public function recompute(string $id, Request $request)
    {
        return new PayrollRunResource($this->payroll->recomputeRun($this->runOrFail($id, $request)));
    }

    #[OA\Post(
        path: '/payroll/runs/{id}/validate',
        tags: ['Payroll'],
        summary: 'Valide la paie : bulletins figés, avances marquées comme retenues',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Paie validée', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRun')),
            new OA\Response(response: 400, description: 'Brouillon incomplet ou déjà validé'),
        ]
    )]
    public function validateRun(string $id, Request $request)
    {
        return new PayrollRunResource($this->payroll->validateRun($this->runOrFail($id, $request), $request->user()));
    }

    #[OA\Post(
        path: '/payroll/runs/{id}/reopen',
        tags: ['Payroll'],
        summary: 'Rouvre la dernière paie validée (non payée)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Paie rouverte', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRun')),
            new OA\Response(response: 400, description: 'Paie payée, brouillon ou période plus récente existante'),
        ]
    )]
    public function reopen(string $id, Request $request)
    {
        return new PayrollRunResource($this->payroll->reopenRun($this->runOrFail($id, $request)));
    }

    #[OA\Post(
        path: '/payroll/runs/{id}/pay',
        tags: ['Payroll'],
        summary: 'Marque la paie comme payée',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['paymentDate'], properties: [
            new OA\Property(property: 'paymentDate', type: 'string', format: 'date'),
            new OA\Property(property: 'paymentReference', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Paie payée', content: new OA\JsonContent(ref: '#/components/schemas/PayrollRun')),
            new OA\Response(response: 400, description: 'Paie non validée'),
        ]
    )]
    public function pay(string $id, Request $request)
    {
        $run = $this->runOrFail($id, $request);
        $data = $request->validate([
            'paymentDate' => ['required', 'date'],
            'paymentReference' => ['nullable', 'string', 'max:100'],
        ]);

        return new PayrollRunResource($this->payroll->markPaid($run, $request->user(), $data['paymentDate'], $data['paymentReference'] ?? null));
    }

    #[OA\Delete(
        path: '/payroll/runs/{id}',
        tags: ['Payroll'],
        summary: 'Supprime une paie en brouillon',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Brouillon supprimé'),
            new OA\Response(response: 400, description: 'Paie validée'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $this->payroll->deleteRun($this->runOrFail($id, $request));

        return response()->json(['message' => 'Paie supprimée']);
    }

    #[OA\Get(
        path: '/payroll/runs/{id}/export',
        tags: ['Payroll'],
        summary: 'Journal de paie CSV',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Fichier CSV', content: new OA\MediaType(mediaType: 'text/csv'))]
    )]
    public function export(string $id, Request $request)
    {
        $run = $this->runOrFail($id, $request);
        $filename = sprintf('journal-paie-%04d-%02d.csv', $run->year, $run->month);

        return response($this->payroll->exportCsv($run), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function runOrFail(string $id, Request $request): PayrollRun
    {
        $run = $this->payroll->findRun($id, $this->enterpriseCompanyId($request));
        if (! $run) {
            abort(404, 'Période de paie introuvable.');
        }

        return $run;
    }
}
