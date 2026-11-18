<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'start_date',
        'end_date',
        'period_months',
        'auto_renew',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'period_months' => 'integer',
            'auto_renew' => 'boolean',
        ];
    }

    /**
     * Exclut les abonnements dont la date de fin est dépassée, même si le
     * cron d'expiration n'a pas encore changé leur statut.
     */
    public function scopeNotPastEndDate(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
