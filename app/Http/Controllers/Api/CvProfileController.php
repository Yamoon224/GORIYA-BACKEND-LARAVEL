<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCvProfileRequest;
use App\Http\Resources\CvProfileResource;
use App\Services\CvProfileService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Profil CV', description: 'Profil extrait du CV et validé par le candidat (un par utilisateur)')]
class CvProfileController extends Controller
{
    public function __construct(private readonly CvProfileService $cvProfileService) {}

    #[OA\Get(
        path: '/me/cv-profile',
        tags: ['Profil CV'],
        summary: "Récupère le profil extrait du CV de l'utilisateur authentifié (profile = null s'il n'en a pas)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Profil', content: new OA\JsonContent(ref: '#/components/schemas/CvProfile')),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function show(Request $request)
    {
        return new CvProfileResource($this->cvProfileService->findForUser($request->user()));
    }

    #[OA\Put(
        path: '/me/cv-profile',
        tags: ['Profil CV'],
        summary: 'Enregistre le profil extrait du CV (upsert)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/SaveCvProfileRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Profil enregistré', content: new OA\JsonContent(ref: '#/components/schemas/CvProfile')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 400, description: 'Validation échouée'),
        ]
    )]
    public function update(SaveCvProfileRequest $request)
    {
        return new CvProfileResource(
            $this->cvProfileService->saveForUser($request->user(), $request->validated('profile'))
        );
    }
}
