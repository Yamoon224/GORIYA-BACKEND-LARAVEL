<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserFeatureUsageService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Quota des fonctionnalités "Limité" du forfait actif de l'utilisateur
 * authentifié (création de CV, génération de documents, analyse de CV) —
 * pendant de /anonymous-usage pour un compte connecté, scopé par abonnement
 * plutôt que par deviceId. Voir UserFeatureUsageService.
 */
#[OA\Tag(name: 'Feature Usage', description: "Quota des fonctionnalités \"Limité\" du forfait actif")]
class FeatureUsageController extends Controller
{
    public function __construct(private readonly UserFeatureUsageService $featureUsageService) {}

    #[OA\Get(
        path: '/me/feature-usage/{featureKey}',
        tags: ['Feature Usage'],
        summary: 'Statut du quota (lecture seule, ne consomme rien)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'featureKey', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['cv_creation', 'document_generation', 'cv_analysis'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statut du quota',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'allowed', type: 'boolean'),
                    new OA\Property(property: 'used', type: 'integer'),
                    new OA\Property(property: 'remaining', type: 'integer'),
                    new OA\Property(property: 'limit', type: 'integer', description: '0 si la fonctionnalité n\'est pas incluse dans le forfait actif'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function status(string $featureKey, Request $request)
    {
        return response()->json($this->featureUsageService->status($request->user(), $featureKey));
    }

    #[OA\Post(
        path: '/me/feature-usage/{featureKey}/consume',
        tags: ['Feature Usage'],
        summary: "Consomme une tentative — à appeler juste avant d'exécuter la fonctionnalité",
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'featureKey', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['cv_creation', 'document_generation', 'cv_analysis'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Quota mis à jour (`allowed: false` si la limite est déjà atteinte ou la fonctionnalité non incluse — pas une erreur HTTP, au frontend de bloquer l\'action)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'allowed', type: 'boolean'),
                    new OA\Property(property: 'used', type: 'integer'),
                    new OA\Property(property: 'remaining', type: 'integer'),
                    new OA\Property(property: 'limit', type: 'integer'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function consume(string $featureKey, Request $request)
    {
        return response()->json($this->featureUsageService->consume($request->user(), $featureKey));
    }
}
