<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '', // parité stricte avec NestJS : pas de préfixe /api
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'auth.apikey' => \App\Http\Middleware\EnsureValidApiKey::class,
        ]);

        // Résout App::getLocale() pour chaque requête (validation Laravel +
        // prompts IA via InteractsWithClaude::localizedInstruction()) —
        // voir SetLocale. Appliqué à toutes les routes (routes/api.php,
        // seul fichier de routes réellement utilisé par cette app).
        $middleware->api(prepend: [\App\Http\Middleware\SetLocale::class]);

        // Sans ceci, Laravel peut réordonner ThrottleRequests avant
        // EnsureValidApiKey (classe custom absente de sa liste de priorité
        // par défaut) — le rate limiter par client lisait alors
        // 'api_client' avant qu'il soit posé dans $request->attributes et
        // retombait sur la limite par défaut (60/min par IP). Confirmé en
        // testant contre un serveur réel : sans cette ligne, un client à
        // rate_limit_per_minute=2 n'était jamais throttlé. prependToPriorityList
        // insère dans la liste par défaut sans l'écraser (contrairement à
        // priority(), qui la remplacerait entièrement).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: \App\Http\Middleware\EnsureValidApiKey::class,
        );

        // API pure JSON, aucune route 'login' : sans ceci, Authenticate::redirectTo()
        // appelle route('login') pour les requêtes qui n'attendent pas du JSON et
        // fait planter la requête (RouteNotFoundException) avant même d'atteindre
        // notre rendu JSON personnalisé de AuthenticationException ci-dessous.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Réplique le format {statusCode, message, error} du ValidationPipe /
        // exception filter par défaut de Nest, au lieu du 422 {message, errors}
        // par défaut de Laravel.
        $exceptions->render(function (ValidationException $e, Request $request) {
            $first = collect($e->errors())->flatten()->first();

            return response()->json([
                'statusCode' => 400,
                'message' => $first,
                'error' => 'Bad Request',
            ], 400);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'statusCode' => 401,
                'message' => 'Unauthorized',
                'error' => 'Unauthorized',
            ], 401);
        });

        // Couvre les abort(400|401|403|404, 'message') des contrôleurs, ainsi
        // que les 404/405 de routing, avec la même forme.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();

            return response()->json([
                'statusCode' => $status,
                'message' => $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error'),
                'error' => Response::$statusTexts[$status] ?? 'Error',
            ], $status);
        });
    })->create();
