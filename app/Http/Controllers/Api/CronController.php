<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Contracts\UserSubscriptionRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Points d'entrée pour un cron externe (cron-job.org…). Protégés par le secret
 * CRON_SECRET, transmis en en-tête `Authorization: Bearer …` ou en `?token=`.
 */
class CronController extends Controller
{
    public function expireSubscriptions(Request $request, UserSubscriptionRepositoryInterface $subscriptions): JsonResponse
    {
        $secret = (string) config('services.cron.secret');
        $given = (string) ($request->bearerToken() ?? $request->query('token', ''));

        if ($secret === '') {
            return response()->json(['message' => 'CRON_SECRET non configuré sur le serveur'], 401);
        }

        if (! hash_equals($secret, trim($given))) {
            return response()->json(['message' => 'Token invalide'], 401);
        }

        return response()->json(['expired' => $subscriptions->expireOverdue()]);
    }
}
