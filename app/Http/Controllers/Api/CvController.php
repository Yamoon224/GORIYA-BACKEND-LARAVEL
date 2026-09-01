<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCvRequest;
use App\Http\Resources\CvResource;
use App\Services\CvService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'CV', description: 'Brouillon du créateur de CV (un par utilisateur)')]
class CvController extends Controller
{
    public function __construct(private readonly CvService $cvService) {}

    #[OA\Get(
        path: '/me/cv',
        tags: ['CV'],
        summary: "Récupère le brouillon de CV de l'utilisateur authentifié (créé vide s'il n'existe pas)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Brouillon', content: new OA\JsonContent(ref: '#/components/schemas/Cv')),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function show(Request $request)
    {
        return new CvResource($this->cvService->getOrCreateForUser($request->user()));
    }

    #[OA\Put(
        path: '/me/cv',
        tags: ['CV'],
        summary: 'Enregistre le brouillon de CV (upsert)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/SaveCvRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Brouillon enregistré', content: new OA\JsonContent(ref: '#/components/schemas/Cv')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(SaveCvRequest $request)
    {
        return new CvResource($this->cvService->saveForUser($request->user(), $request->validated()));
    }

    #[OA\Delete(
        path: '/me/cv',
        tags: ['CV'],
        summary: "Supprime le brouillon de CV de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Brouillon supprimé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function destroy(Request $request)
    {
        $this->cvService->deleteForUser($request->user());

        return response()->noContent();
    }
}
