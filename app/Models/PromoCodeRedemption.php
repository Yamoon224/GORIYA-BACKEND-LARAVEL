<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\PromoRedemptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne par tentative d'utilisation d'un PromoCode au checkout. Créée
 * PENDING avec la Transaction, confirmée (et compte alors dans
 * PromoCode::used_count) uniquement quand le paiement aboutit — voir
 * PromoCodeService::confirmRedemption()/cancelRedemption().
 */
class PromoCodeRedemption extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'promo_code_id',
        'influencer_id',
        'user_id',
        'transaction_id',
        'subscription_id',
        'plan_id',
        'original_amount',
        'discount_amount',
        'final_amount',
        'currency',
        'commission_rate',
        'commission_amount',
        'status',
        'payout_id',
        'redeemed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'status' => PromoRedemptionStatus::class,
            'redeemed_at' => 'datetime',
        ];
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(InfluencerPayout::class, 'payout_id');
    }
}
