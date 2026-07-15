<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Préférence de langue de l'utilisateur — voir SetLocale (middleware) pour
 * la résolution effective par requête (cette préférence est prioritaire
 * sur Accept-Language une fois enregistrée).
 */
#[OA\Tag(name: 'Locale', description: "Préférence de langue de l'utilisateur (FR/EN/PT/AR)")]
class UserLocaleController extends Controller
{
    #[OA\Get(
        path: '/me/locale',
        tags: ['Locale'],
        summary: "Locale active pour cette requête (résolue par SetLocale)",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Locale active',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'locale', type: 'string')])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function show(Request $request)
    {
        return response()->json(['locale' => app()->getLocale()]);
    }

    #[OA\Patch(
        path: '/me/locale',
        tags: ['Locale'],
        summary: 'Enregistre la préférence de langue de l\'utilisateur',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateLocaleRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Locale enregistrée',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'locale', type: 'string')])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 422, description: 'Validation échouée'),
        ]
    )]
    public function update(UpdateLocaleRequest $request)
    {
        $locale = $request->validated()['locale'];
        $request->user()->update(['locale' => $locale]);

        return response()->json(['locale' => $locale]);
    }
}
