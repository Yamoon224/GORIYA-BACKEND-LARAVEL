<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePublicProfileRequest;
use App\Http\Resources\PublicProfileResource;
use App\Services\PublicProfileService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Public Profiles', description: 'Profil Public GORIYA — page vitrine (goriya.net/{slug})')]
class PublicProfileController extends Controller
{
    public function __construct(private readonly PublicProfileService $profileService) {}

    /*
    |----------------------------------------------------------------------
    | PROFIL PUBLIC PAR SLUG (aucune auth — 404 si non publié)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/profiles/{slug}',
        tags: ['Public Profiles'],
        summary: 'Affiche un profil public par son slug',
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Profil public (agrège portfolios et pitchs vidéo)'),
            new OA\Response(response: 404, description: 'Profil introuvable ou non publié'),
        ]
    )]
    public function show(string $slug)
    {
        $profile = $this->profileService->showPublic($slug);

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
    | MISE À JOUR (slug/thème/visibilité/SEO)
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/profile/me',
        tags: ['Public Profiles'],
        summary: 'Met à jour le profil public (slug, thème, visibilité, SEO)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePublicProfileRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Profil mis à jour', content: new OA\JsonContent(ref: '#/components/schemas/PublicProfile')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(UpdatePublicProfileRequest $request)
    {
        $profile = $this->profileService->getOrCreateForUser($request->user());
        $updated = $this->profileService->update($profile, $request->validated());

        return new PublicProfileResource($updated);
    }
}
