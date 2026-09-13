<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\BillingPeriod;
use App\Enums\SubscriptionUserType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'price',
        'billing_period',
        'available_periods',
        'user_type',
        'features',
        'notification_level',
        'feature_limits',
        'reset_price',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'user_type' => SubscriptionUserType::class,
            'price' => 'decimal:2',
            'available_periods' => 'array',
            'features' => 'array',
            'feature_limits' => 'array',
            'reset_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Durées d'engagement permises à l'achat, en mois. Un plan sans
     * `available_periods` (tous les plans USER) est fixe à 1 mois — voir
     * SubscriptionService::checkout().
     *
     * @return array<int, int>
     */
    public function allowedPeriods(): array
    {
        return $this->available_periods ?: [1];
    }

    /**
     * Limite d'utilisations de `$featureKey` pour ce plan ("cv_analysis",
     * "cv_creation", "document_generation"), ou null si la fonctionnalité
     * n'est simplement pas incluse dans ce plan — distinct de "illimitée" :
     * aucun plan actuel n'offre un usage illimité de ces 3 fonctionnalités
     * (voir UserFeatureUsageService).
     */
    public function featureLimit(string $featureKey): ?int
    {
        return $this->feature_limits[$featureKey] ?? null;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class, 'plan_id');
    }

    /**
     * Un plan à 0 est activable sans paiement (voir
     * SubscriptionService::subscribe) — c'est le socle de l'offre gratuite
     * entreprise, qui doit rester distinguable des forfaits payants pour le
     * gating des fonctionnalités premium.
     */
    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    /**
     * Palier d'accès dérivé du prix : FREE (offre de découverte) ou PAID
     * (forfait complet). Consommé par SubscriptionService::check(), que le
     * frontend utilise pour n'ouvrir les pages premium qu'aux forfaits payants.
     */
    public function tier(): string
    {
        return $this->isFree() ? 'FREE' : 'PAID';
    }
}
