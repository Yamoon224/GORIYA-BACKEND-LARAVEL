<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\InfluencerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Promoteur/influenceur pouvant être rattaché à un ou plusieurs PromoCode.
 * Le taux de commission par défaut peut être surchargé au niveau du code
 * (deals différents par partenariat) — voir PromoCode::effectiveCommissionRate().
 */
class Influencer extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'default_commission_rate',
        'notes',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_commission_rate' => 'decimal:2',
            'status' => InfluencerStatus::class,
        ];
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(InfluencerPayout::class);
    }

    /**
     * Solde de commission dû : redemptions confirmées, pas encore incluses
     * dans un versement.
     */
    public function unpaidBalance(): float
    {
        return (float) $this->redemptions()
            ->where('status', 'CONFIRMED')
            ->whereNull('payout_id')
            ->sum('commission_amount');
    }
}
