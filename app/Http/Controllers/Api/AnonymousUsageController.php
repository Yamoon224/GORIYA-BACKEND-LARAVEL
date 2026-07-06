<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumeAnonymousUsageRequest;
use App\Services\AnonymousUsageService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Mirroir de backend/src/anonymous-usage — quota d'usage gratuit avant
 * inscription, identifié par deviceId. Entièrement public.
 */
#[OA\Tag(name: 'Anonymous Usage', description: "Quota d'usage gratuit avant inscription, identifié par deviceId")]
class AnonymousUsageController extends Controller
{
    public function __construct(private readonly AnonymousUsageService $anonymousUsageService) {}

    #[OA\Post(
        path: '/anonymous-usage/consume',
        tags: ['Anonymous Usage'],
        summary: "Consomme une unité de quota gratuit pour un device/feature donnés",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ConsumeAnonymousUsageRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Quota mis à jour',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'allowed', type: 'boolean'),
                    new OA\Property(property: 'used', type: 'integer'),
                    new OA\Property(property: 'remaining', type: 'integer'),
                    new OA\Property(property: 'limit', type: 'integer'),
                ])
            ),
        ]
    )]
    public function consume(ConsumeAnonymousUsageRequest $request)
    {
        $data = $request->validated();

        return response()->json($this->anonymousUsageService->consume($data['deviceId'], $data['featureKey']));
    }

    #[OA\Get(
        path: '/anonymous-usage/status',
        tags: ['Anonymous Usage'],
        summary: "Statut du quota gratuit pour un device/feature donnés",
        parameters: [
            new OA\Parameter(name: 'deviceId', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'featureKey', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statut du quota',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'allowed', type: 'boolean'),
                    new OA\Property(property: 'used', type: 'integer'),
                    new OA\Property(property: 'remaining', type: 'integer'),
                    new OA\Property(property: 'limit', type: 'integer'),
                ])
            ),
        ]
    )]
    public function status(Request $request)
    {
        return response()->json($this->anonymousUsageService->status(
            $request->query('deviceId'),
            $request->query('featureKey'),
        ));
    }
}
