<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterDeviceTokenRequest;
use App\Services\DeviceTokenService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Device Tokens', description: "Jetons push de l'app mobile (FCM)")]
class DeviceTokensController extends Controller
{
    public function __construct(private readonly DeviceTokenService $deviceTokenService) {}

    #[OA\Post(
        path: '/device-tokens',
        tags: ['Device Tokens'],
        summary: 'Enregistre (ou met à jour) le jeton push de cet appareil',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/RegisterDeviceTokenRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Jeton enregistré'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(RegisterDeviceTokenRequest $request)
    {
        $data = $request->validated();
        $this->deviceTokenService->register($request->user(), $data['token'], $data['platform']);

        return response()->json(['message' => 'Device token registered']);
    }

    #[OA\Delete(
        path: '/device-tokens/{token}',
        tags: ['Device Tokens'],
        summary: 'Désenregistre un jeton push (déconnexion / désinstallation)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Jeton supprimé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function destroy(string $token, Request $request)
    {
        $this->deviceTokenService->unregister($request->user(), $token);

        return response()->json(['message' => 'Device token removed']);
    }
}
