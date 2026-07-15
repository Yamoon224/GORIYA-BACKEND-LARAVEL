<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CareerDashboardService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Career Dashboard', description: 'Tableau de bord carrière personnel (progression du candidat)')]
class CareerDashboardController extends Controller
{
    public function __construct(private readonly CareerDashboardService $careerDashboardService) {}

    #[OA\Get(
        path: '/career-dashboard',
        tags: ['Career Dashboard'],
        summary: "Progression carrière de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Tableau de bord carrière'),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function show(Request $request)
    {
        return response()->json($this->careerDashboardService->forUser($request->user()));
    }
}
