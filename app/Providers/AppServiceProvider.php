<?php

namespace App\Providers;

use App\Contracts\AiAnalysisServiceInterface;
use App\Contracts\AvatarGenerationServiceInterface;
use App\Contracts\ChatAiServiceInterface;
use App\Contracts\CompanyResearchServiceInterface;
use App\Contracts\HrInsightsServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PitchAiServiceInterface;
use App\Contracts\PresentationAiServiceInterface;
use App\Contracts\PushNotificationServiceInterface;
use App\Contracts\VideoCallProviderInterface;
use App\Services\AnthropicChatService;
use App\Services\AnthropicHrInsightsService;
use App\Services\AnthropicPitchService;
use App\Services\AnthropicPresentationService;
use App\Services\AnthropicResearchService;
use App\Services\AnthropicService;
use App\Services\DIdAvatarService;
use App\Services\FcmPushNotificationService;
use App\Services\LunionMeetService;
use App\Services\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        $this->app->bind(VideoCallProviderInterface::class, LunionMeetService::class);
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
