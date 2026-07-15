<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachPitchVideoRequest;
use App\Http\Requests\CreatePitchRequest;
use App\Http\Requests\SendPitchToRecruiterRequest;
use App\Http\Resources\PitchResource;
use App\Services\PitchService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Pitches', description: "Pitch Goriya — remplace ou complète la lettre de motivation traditionnelle")]
class PitchController extends Controller
{
    public function __construct(private readonly PitchService $pitchService) {}

    /*
    |----------------------------------------------------------------------
    | HISTORIQUE (scopé à l'utilisateur authentifié)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/pitches',
        tags: ['Pitches'],
        summary: "Liste des pitchs de l'utilisateur authentifié",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des pitchs',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Pitch'))
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function index(Request $request)
    {
        return PitchResource::collection($this->pitchService->listFor($request->user()));
    }

    /*
    |----------------------------------------------------------------------
    | CREATE (génère le script IA, ou utilise celui fourni, puis le score)
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/pitches',
        tags: ['Pitches'],
        summary: 'Génère (ou enregistre) un pitch et le score',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreatePitchRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pitch créé', content: new OA\JsonContent(ref: '#/components/schemas/Pitch')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function store(CreatePitchRequest $request)
    {
        $pitch = $this->pitchService->create($request->user(), $request->validated());

        return new PitchResource($pitch);
    }

    /*
    |----------------------------------------------------------------------
    | DÉTAIL
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/pitches/{id}',
        tags: ['Pitches'],
        summary: "Détail d'un pitch",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Pitch trouvé', content: new OA\JsonContent(ref: '#/components/schemas/Pitch')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Pitch introuvable'),
        ]
    )]
    public function show(string $id, Request $request)
    {
        $pitch = $this->pitchService->find($id, $request->user());

        if (! $pitch) {
            abort(404, 'Pitch not found');
        }

        return new PitchResource($pitch);
    }

    /*
    |----------------------------------------------------------------------
    | UPLOAD VIDÉO (bascule le pitch en format VIDEO, scoring asynchrone)
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/pitches/{id}/video',
        tags: ['Pitches'],
        summary: 'Attache une vidéo au pitch (scoring en arrière-plan)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/AttachPitchVideoRequest')
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Vidéo attachée, statut PROCESSING', content: new OA\JsonContent(ref: '#/components/schemas/Pitch')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Pitch introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function storeVideo(string $id, AttachPitchVideoRequest $request)
    {
        $pitch = $this->pitchService->find($id, $request->user());

        if (! $pitch) {
            abort(404, 'Pitch not found');
        }

        $updated = $this->pitchService->attachVideo($pitch, $request->file('video'));

        return new PitchResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | STUDIO IA — AVATAR ANIMÉ (rendu asynchrone via D-ID, voir
    | PitchService::renderAvatarVideo() et PollAvatarRenderJob)
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/pitches/{id}/avatar-video',
        tags: ['Pitches'],
        summary: "Génère un avatar animé (Studio IA) lisant le script du pitch",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Rendu lancé, statut PROCESSING', content: new OA\JsonContent(ref: '#/components/schemas/Pitch')),
            new OA\Response(response: 400, description: 'Photo de profil ou script du pitch manquant'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Pitch introuvable'),
            new OA\Response(response: 502, description: 'Erreur du fournisseur D-ID'),
        ]
    )]
    public function renderAvatar(string $id, Request $request)
    {
        $pitch = $this->pitchService->find($id, $request->user());

        if (! $pitch) {
            abort(404, 'Pitch not found');
        }

        $updated = $this->pitchService->renderAvatarVideo($pitch, $request->user());

        return new PitchResource($updated);
    }

    /*
    |----------------------------------------------------------------------
    | VISIBILITÉ (opt-in pour affichage sur le Profil Public GORIYA)
    |----------------------------------------------------------------------
    */
    #[OA\Patch(
        path: '/pitches/{id}/visibility',
        tags: ['Pitches'],
        summary: 'Bascule la visibilité publique du pitch (Profil Public GORIYA)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Visibilité mise à jour', content: new OA\JsonContent(ref: '#/components/schemas/Pitch')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Pitch introuvable'),
        ]
    )]
    public function toggleVisibility(string $id, Request $request)
    {
        $pitch = $this->pitchService->find($id, $request->user());

        if (! $pitch) {
            abort(404, 'Pitch not found');
        }

        return new PitchResource($this->pitchService->toggleVisibility($pitch));
    }

    /*
    |----------------------------------------------------------------------
    | ENVOI AU RECRUTEUR (réutilise CandidatureService::create())
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/pitches/{id}/send',
        tags: ['Pitches'],
        summary: 'Envoie le pitch au recruteur (crée/attache une candidature)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SendPitchToRecruiterRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Candidature créée avec le pitch attaché', content: new OA\JsonContent(ref: '#/components/schemas/Candidature')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Pitch introuvable'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function sendToRecruiter(string $id, SendPitchToRecruiterRequest $request)
    {
        $pitch = $this->pitchService->find($id, $request->user());

        if (! $pitch) {
            abort(404, 'Pitch not found');
        }

        $candidature = $this->pitchService->sendToRecruiter($pitch, $request->user(), $request->validated()['jobOfferId']);

        return response()->json($candidature);
    }

    /*
    |----------------------------------------------------------------------
    | DELETE
    |----------------------------------------------------------------------
    */
    #[OA\Delete(
        path: '/pitches/{id}',
        tags: ['Pitches'],
        summary: 'Supprime un pitch',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Pitch supprimé', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Pitch deleted successfully')])),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Pitch introuvable'),
        ]
    )]
    public function destroy(string $id, Request $request)
    {
        $pitch = $this->pitchService->find($id, $request->user());

        if (! $pitch) {
            abort(404, 'Pitch not found');
        }

        $this->pitchService->delete($pitch);

        return response()->json(['message' => 'Pitch deleted successfully']);
    }
}
