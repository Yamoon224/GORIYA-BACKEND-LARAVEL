<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePublicProfileRequest;
use App\Http\Resources\PublicProfileResource;
use App\Services\PublicProfileService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Public Profiles', description: 'Profil Public GORIYA — page vitrine (goriya.net/p/{slug ou uuid})')]
class PublicProfileController extends Controller
{
    public function __construct(private readonly PublicProfileService $profileService) {}

    /*
    |----------------------------------------------------------------------
    | PROFIL PAR SLUG OU UUID — authentification facultative : le jeton, s'il
    | est présent, décide du mode (propriétaire / membre / public).
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/profiles/{ref}',
        tags: ['Public Profiles'],
        summary: 'Affiche un profil par son URL personnalisée ou par l\'uuid du membre',
        description: 'Sans jeton : 404 si le profil n\'est pas public. Avec jeton : visible des membres ; `viewerMode` vaut owner, member ou public.',
        parameters: [new OA\Parameter(name: 'ref', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Profil (identité, portfolios, pitchs vidéo publics, activité récente)'),
            new OA\Response(response: 404, description: 'Profil introuvable ou non visible'),
        ]
    )]
    public function show(string $ref)
    {
        $profile = $this->profileService->showFor($ref, auth('api')->user());

        if (! $profile) {
            abort(404, 'Public profile not found');
        }

        return response()->json($profile);
    }

    /*
    |----------------------------------------------------------------------
    | MON PROFIL (créé à la demande s'il n'existe pas encore)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/profile/me',
        tags: ['Public Profiles'],
        summary: "Profil public de l'utilisateur authentifié (créé automatiquement si absent)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Profil', content: new OA\JsonContent(ref: '#/components/schemas/PublicProfile')),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function me(Request $request)
    {
        return new PublicProfileResource($this->profileService->getOrCreateForUser($request->user()));
    }

    /*
    |----------------------------------------------------------------------
    | MISE À JOUR (URL personnalisée / thème / visibilité / SEO)
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/profile/me',
        tags: ['Public Profiles'],
        summary: 'Met à jour le profil public (URL personnalisée, thème, visibilité, SEO)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePublicProfileRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Profil mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/PublicProfile')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée, ou URL déjà prise'),
        ]
    )]
    public function update(UpdatePublicProfileRequest $request)
    {
        $profile = $this->profileService->getOrCreateForUser($request->user());
        $updated = $this->profileService->update($profile, $request->validated());

        return new PublicProfileResource($updated);
    }
}
