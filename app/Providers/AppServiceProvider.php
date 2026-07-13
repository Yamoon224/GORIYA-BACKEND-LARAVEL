<?php

namespace App\Providers;

use App\Contracts\AiAnalysisServiceInterface;
use App\Contracts\CompanyResearchServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PitchAiServiceInterface;
use App\Contracts\PresentationAiServiceInterface;
use App\Services\AnthropicPitchService;
use App\Services\AnthropicPresentationService;
use App\Services\AnthropicResearchService;
use App\Services\AnthropicService;
use App\Services\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
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
    }
}
