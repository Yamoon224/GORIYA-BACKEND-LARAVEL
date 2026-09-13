<?php

namespace App\Http\Middleware;

use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie que le forfait ACTIF de l'utilisateur inclut `$featureKey` — pas
 * seulement qu'un abonnement quelconque existe. Sans ça, un compte
 * Grouilleur (ou une entreprise Business) pouvait utiliser des
 * fonctionnalités réservées à un forfait supérieur en appelant l'API
 * directement, la seule garde étant côté frontend (SubscriptionGate).
 *
 * Doit être appliqué après `auth:api`. Utilisation : `->middleware('plan.
 * feature:simulation_entretien')`. Voir SubscriptionPlan::hasFeature() et
 * les clés listées dans SubscriptionPlanSeeder.
 */
class EnsurePlanIncludesFeature
{
    public function __construct(
        private readonly UserSubscriptionRepositoryInterface $userSubscriptionRepository,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $subscription = $this->userSubscriptionRepository->findActiveForUser($user->id);
        if (! $subscription?->plan?->hasFeature($featureKey)) {
            abort(403, "Cette fonctionnalité n'est pas incluse dans votre forfait actuel.");
        }

        return $next($request);
    }
}
