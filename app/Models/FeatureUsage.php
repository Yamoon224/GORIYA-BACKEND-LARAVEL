<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compteur d'utilisations d'une fonctionnalité "Limité" (cv_analysis,
 * cv_creation, document_generation) pour un abonnement donné — mirroir
 * authentifié de AnonymousUsage (deviceId + featureKey), scopé ici par
 * user_subscription_id : un réabonnement/changement de forfait crée une
 * nouvelle UserSubscription, donc repart naturellement de 0. Voir
 * UserFeatureUsageService.
 */
class FeatureUsage extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'user_subscription_id',
        'feature_key',
        'count',
    ];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class, 'user_subscription_id');
    }
}
