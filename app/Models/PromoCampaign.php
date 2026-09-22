<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\PromoCampaignStatus;
use App\Enums\PromoDiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCampaign extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'discount_type',
        'discount_value',
        'applicable_user_types',
        'applicable_plan_ids',
        'min_plan_price',
        'starts_at',
        'ends_at',
        'status',
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
            'applicable_user_types' => 'array',
            'applicable_plan_ids' => 'array',
            'min_plan_price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => PromoCampaignStatus::class,
        ];
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PromoCode::class, 'campaign_id');
    }

    /**
     * Statut ACTIVE + à l'intérieur de la fenêtre de dates, si définie.
     */
    public function isActiveNow(): bool
    {
        if ($this->status !== PromoCampaignStatus::ACTIVE) {
            return false;
        }

        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function appliesToUserType(string $userType): bool
    {
        return in_array($userType, $this->applicable_user_types ?? [], true);
    }

    public function appliesToPlan(string $planId): bool
    {
        return $this->applicable_plan_ids === null || in_array($planId, $this->applicable_plan_ids, true);
    }
}
