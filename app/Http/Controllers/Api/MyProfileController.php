<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMyProfileRequest;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Informations de profil de l'utilisateur authentifié (titre professionnel,
 * localisation, bio). `/auth/profile` ne renvoie que l'identité issue du JWT ;
 * ces champs vivent en base et sont saisis à l'inscription
 * (standard/app/auth/complete-profile) puis relus sur /profil.
 */
#[OA\Tag(name: 'Profil', description: "Profil de l'utilisateur authentifié")]
class MyProfileController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'title' => $user->title,
            'location' => $user->location,
            'bio' => $user->bio,
        ];
    }

    #[OA\Get(
        path: '/me/profile',
        tags: ['Profil'],
        summary: "Profil de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Profil'),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function show(Request $request)
    {
        return response()->json($this->payload($request));
    }

    #[OA\Patch(
        path: '/me/profile',
        tags: ['Profil'],
        summary: 'Met à jour le profil de l\'utilisateur authentifié',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/UpdateMyProfileRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Profil mis à jour'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(UpdateMyProfileRequest $request)
    {
        $data = $request->validated();

        if ($data !== []) {
            $request->user()->update($data);
        }

        return response()->json($this->payload($request));
    }
}
