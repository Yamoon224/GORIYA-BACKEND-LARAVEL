<?php

namespace App\Providers;

use App\Contracts\AiAnalysisServiceInterface;
use App\Contracts\AvatarGenerationServiceInterface;
use App\Contracts\ChatAiServiceInterface;
use App\Contracts\CompanyResearchServiceInterface;
use App\Contracts\DashboardInsightsServiceInterface;
use App\Contracts\HrInsightsServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PitchAiServiceInterface;
use App\Contracts\PresentationAiServiceInterface;
use App\Contracts\PushNotificationServiceInterface;
use App\Contracts\VideoCallProviderInterface;
use App\Services\AnthropicChatService;
use App\Services\AnthropicDashboardInsightsService;
use App\Services\AnthropicHrInsightsService;
use App\Services\AnthropicPitchService;
use App\Services\AnthropicPresentationService;
use App\Services\AnthropicResearchService;
use App\Services\AnthropicService;
use App\Services\DIdAvatarService;
use App\Services\FcmPushNotificationService;
use App\Services\LunionMeetService;
use App\Services\PaymentGatewayManager;
use Carbon\CarbonInterface;
use Carbon\FactoryImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Tymon\JWTAuth\Providers\Storage\Illuminate as JwtCacheStorage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Frontières vers les intégrations externes (paiement, Anthropic) —
        // pas des repositories, donc bindées ici plutôt que dans
        // RepositoryServiceProvider (clarté sémantique).
        //
        // PaymentGatewayManager résout Kkiapay/Wave/Stripe par nom selon
        // config('services.payment.enabled_gateways') — voir sa docblock.
        // SubscriptionService dépend directement de la classe concrète (pas
        // seulement de l'interface) pour accéder à resolve().
        $this->app->singleton(PaymentGatewayManager::class);
        $this->app->bind(PaymentGatewayInterface::class, PaymentGatewayManager::class);
        $this->app->bind(AiAnalysisServiceInterface::class, AnthropicService::class);
        $this->app->bind(CompanyResearchServiceInterface::class, AnthropicResearchService::class);
        $this->app->bind(PitchAiServiceInterface::class, AnthropicPitchService::class);
        $this->app->bind(PresentationAiServiceInterface::class, AnthropicPresentationService::class);
        $this->app->bind(ChatAiServiceInterface::class, AnthropicChatService::class);
        $this->app->bind(AvatarGenerationServiceInterface::class, DIdAvatarService::class);
        $this->app->bind(PushNotificationServiceInterface::class, FcmPushNotificationService::class);
        $this->app->bind(HrInsightsServiceInterface::class, AnthropicHrInsightsService::class);
        $this->app->bind(DashboardInsightsServiceInterface::class, AnthropicDashboardInsightsService::class);
        $this->app->bind(VideoCallProviderInterface::class, LunionMeetService::class);

        // Liste noire JWT (déconnexion, rotation de jeton) : consultée à chaque
        // requête authentifiée. Sur le cache `database`, cela coûtait deux
        // requêtes SQL avant même de charger l'utilisateur ; elle vit donc dans
        // le cache fichier, sauf store imposé par JWT_BLACKLIST_STORE. Les
        // autres usages du cache (réglages conservés « pour toujours ») restent
        // sur le store par défaut.
        $this->app->singleton('tymon.jwt.provider.storage', function ($app) {
            $store = config('jwt.blacklist_store') ?: (config('cache.default') === 'database' ? 'file' : null);

            return new JwtCacheStorage($app['cache']->store($store));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Les VM/contrôleurs NestJS renvoient des objets/tableaux bruts (pas
        // d'enveloppe {"data": ...}) sauf pour la pagination, dont la forme
        // {data, meta} est déjà répliquée explicitement via ApiResponse. Sans
        // ça, Laravel enveloppe automatiquement toute Resource/collection
        // top-level dans "data", ce qui casse la parité pour index/show.
        JsonResource::withoutWrapping();

        // Filet anti-N+1 : une relation lue sur un modèle issu d'une collection
        // est chargée en une requête pour toute la collection, au lieu d'une
        // requête par ligne. Les `with()` explicites restent la règle sur les
        // listes ; ceci rattrape les relations lues par une Resource ou un
        // service sans avoir été préchargées.
        Model::automaticallyEagerLoadRelationships();

        // Dates des réponses JSON : même chaîne ISO 8601 UTC que le format par
        // défaut de Carbon (2026-10-07T12:00:00.000000Z), produite par
        // DateTime::format() au lieu de isoFormat() — une vingtaine de fois
        // plus rapide, ce qui pèse sur toute liste (4 dates par ligne).
        FactoryImmutable::getDefaultInstance()->serializeUsing(static fn (CarbonInterface $date): string => $date->getOffset() === 0
            ? $date->format('Y-m-d\TH:i:s.u\Z')
            : $date->avoidMutation()->utc()->format('Y-m-d\TH:i:s.u\Z'));

        // `ilike` est spécifique à PostgreSQL — invalide en SQL sur MySQL/SQLite
        // ("Syntax error ... near 'ilike'"). Ces deux macros donnent une
        // recherche "contient, insensible à la casse" portable sur tous les
        // moteurs, à utiliser partout où le code appelait auparavant
        // ->where($col, 'ilike', "%$val%").
        Builder::macro('whereILike', function (string $column, string $value) {
            /** @var Builder $this */
            return $this->whereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($value).'%']);
        });

        Builder::macro('orWhereILike', function (string $column, string $value) {
            /** @var Builder $this */
            return $this->orWhereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($value).'%']);
        });

        // Rate limiting par client API B2B (EnsureValidApiKey pose
        // 'api_client' dans $request->attributes avant que ce limiteur ne
        // s'exécute — voir la route /external/v1/* : middleware('auth.apikey')
        // doit toujours précéder middleware('throttle:api-client')).
        RateLimiter::for('api-client', function (Request $request) {
            $client = $request->attributes->get('api_client');
            $limit = $client?->rate_limit_per_minute ?? 60;

            return Limit::perMinute($limit)->by($client?->id ?? $request->ip());
        });
    }
}
