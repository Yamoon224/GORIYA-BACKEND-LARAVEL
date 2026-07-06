<?php

namespace App\Providers;

use App\Contracts\AiAnalysisServiceInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Services\AnthropicService;
use App\Services\WaveService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Frontières vers les intégrations externes (Wave, Anthropic) — pas
        // des repositories, donc bindées ici plutôt que dans
        // RepositoryServiceProvider (clarté sémantique).
        $this->app->bind(PaymentGatewayInterface::class, WaveService::class);
        $this->app->bind(AiAnalysisServiceInterface::class, AnthropicService::class);
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
    }
}
