<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\PromoDiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'influencer_id',
        'code',
        'discount_type',
        'discount_value',
        'commission_rate',
        'max_uses',
        'used_count',
        'max_uses_per_user',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => PromoDiscountType::class,
            'discount_value' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'max_uses_per_user' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $code) {
            if ($code->code) {
                $code->code = strtoupper($code->code);
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PromoCampaign::class, 'campaign_id');
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    public function effectiveDiscountType(): PromoDiscountType
    {
        return $this->discount_type ?? $this->campaign->discount_type;
    }

    public function effectiveDiscountValue(): float
    {
        return (float) ($this->discount_value ?? $this->campaign->discount_value);
    }

    /**
     * Taux de commission à appliquer pour ce code : le sien, sinon celui par
     * défaut de son influenceur, sinon aucune commission.
     */
    public function effectiveCommissionRate(): ?float
    {
        if ($this->commission_rate !== null) {
            return (float) $this->commission_rate;
        }

        return $this->influencer?->default_commission_rate !== null
            ? (float) $this->influencer->default_commission_rate
            : null;
    }

    /**
     * Vérifie uniquement l'état intrinsèque du code (actif, dates, quota
     * global) — pas l'éligibilité du plan/utilisateur, gérée par
     * PromoCodeService::validate().
     */
    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }
}
