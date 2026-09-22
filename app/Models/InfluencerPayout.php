<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\InfluencerPayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InfluencerPayout extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'influencer_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'payment_reference',
        'notes',
        'paid_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => InfluencerPayoutStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class, 'payout_id');
    }
}
