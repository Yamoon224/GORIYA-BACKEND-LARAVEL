<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCheckoutRequest;
use App\Http\Requests\SubscribeRequest;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Subscriptions', description: "Plans d'abonnement, souscription et paiement Wave")]
class SubscriptionsController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    /*
    |----------------------------------------------------------------------
    | PLANS (page tarifaire publique)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/subscriptions/plans',
        tags: ['Subscriptions'],
        summary: "Liste des plans d'abonnement actifs",
        parameters: [
            new OA\Parameter(name: 'userType', in: 'query', schema: new OA\Schema(type: 'string', enum: ['USER', 'ENTREPRISE'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des plans',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionPlan'))
            ),
        ]
    )]
    public function plans(Request $request)
    {
        return $this->subscriptionService->plans($request->query('userType'));
    }

    /*
    |----------------------------------------------------------------------
    | SUBSCRIBE
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/subscriptions/subscribe',
        tags: ['Subscriptions'],
        summary: 'Souscrit un utilisateur à un plan (annule tout abonnement actif existant)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SubscribeRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Abonnement créé', content: new OA\JsonContent(ref: '#/components/schemas/UserSubscription')),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Plan non trouvé'),
        ]
    )]
    public function subscribe(SubscribeRequest $request)
    {
        $data = $request->validated();

        return $this->subscriptionService->subscribe($data['userId'], $data['planId']);
    }

    /*
    |----------------------------------------------------------------------
    | MY SUBSCRIPTION — NOTE: userId vient du path, pas du JWT authentifié,
    | limitation héritée du backend NestJS, volontairement préservée.
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/subscriptions/me/{userId}',
        tags: ['Subscriptions'],
        summary: "Abonnement actif de l'utilisateur (userId du path, pas du JWT)",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: "Abonnement actif, ou corps JSON `null` littéral si aucun abonnement", content: new OA\JsonContent(ref: '#/components/schemas/UserSubscription')),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function mySubscription(string $userId)
    {
        $sub = $this->subscriptionService->mySubscription($userId);

        if (! $sub) {
            // response()->json(null) encode {} (Symfony convertit null en
            // ArrayObject) — on force le corps littéral "null" pour matcher
            // res.json(null) côté NestJS.
            return response('null', 200)->header('Content-Type', 'application/json');
        }

        return $sub;
    }

    #[OA\Delete(
        path: '/subscriptions/me/{userId}',
        tags: ['Subscriptions'],
        summary: "Annule l'abonnement actif de l'utilisateur",
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Abonnement annulé',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Abonnement annulé avec succès')])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function cancel(string $userId)
    {
        $this->subscriptionService->cancel($userId);

        return response()->json(['message' => 'Abonnement annulé avec succès']);
    }

    /*
    |----------------------------------------------------------------------
    | SUBSCRIPTION CHECK (feature gating, public)
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/subscriptions/check/{userId}',
        tags: ['Subscriptions'],
        summary: "Vérifie si l'utilisateur a un abonnement actif (feature gating)",
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: "Statut d'abonnement",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'hasSubscription', type: 'boolean'),
                    new OA\Property(property: 'planName', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'EXPIRED', 'CANCELLED'], nullable: true),
                ])
            ),
        ]
    )]
    public function check(string $userId)
    {
        return response()->json($this->subscriptionService->check($userId));
    }

    /*
    |----------------------------------------------------------------------
    | WAVE CHECKOUT
    |----------------------------------------------------------------------
    */
    #[OA\Post(
        path: '/subscriptions/checkout',
        tags: ['Subscriptions'],
        summary: 'Crée une session de paiement Wave pour un plan payant',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCheckoutRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session de paiement créée',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'waveUrl', type: 'string', nullable: true),
                    new OA\Property(property: 'sessionId', type: 'string', nullable: true),
                    new OA\Property(property: 'clientReference', type: 'string'),
                    new OA\Property(property: 'expiresAt', type: 'string', nullable: true),
                ])
            ),
            new OA\Response(response: 400, description: 'Plan gratuit (utiliser /subscribe directement)'),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 404, description: 'Plan non trouvé'),
        ]
    )]
    public function checkout(CreateCheckoutRequest $request)
    {
        return response()->json($this->subscriptionService->checkout($request->validated()));
    }

    #[OA\Get(
        path: '/subscriptions/checkout/verify/{sessionId}',
        tags: ['Subscriptions'],
        summary: 'Vérifie une session de paiement Wave et active (ou retourne) l\'abonnement correspondant',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'userId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'planId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Abonnement actif (existant ou nouvellement créé)', content: new OA\JsonContent(ref: '#/components/schemas/UserSubscription')),
            new OA\Response(response: 400, description: 'Paiement non confirmé'),
            new OA\Response(response: 401, description: 'Non authentifié'),
        ]
    )]
    public function verifyCheckout(string $sessionId, Request $request)
    {
        return $this->subscriptionService->verifyCheckout(
            $sessionId,
            $request->query('userId'),
            $request->query('planId'),
        );
    }

    /*
    |----------------------------------------------------------------------
    | ADMIN
    |----------------------------------------------------------------------
    */
    #[OA\Get(
        path: '/subscriptions/admin/stats',
        tags: ['Subscriptions'],
        summary: 'Statistiques globales des abonnements (Rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistiques',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'active', type: 'integer'),
                    new OA\Property(property: 'expired', type: 'integer'),
                    new OA\Property(property: 'cancelled', type: 'integer'),
                    new OA\Property(property: 'revenue', type: 'number', format: 'float'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function adminStats()
    {
        return response()->json($this->subscriptionService->adminStats());
    }

    #[OA\Get(
        path: '/subscriptions/admin/all',
        tags: ['Subscriptions'],
        summary: 'Liste paginée de tous les abonnements, avec plan et utilisateur (Rôle ADMIN requis)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Page de résultats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/UserSubscription')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Non authentifié'),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis'),
        ]
    )]
    public function adminAll(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 20);

        return $this->subscriptionService->adminAll($page, $limit);
    }
}
