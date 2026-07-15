<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identifiant d'intégration API B2B (ATS/SIRH) — voir ApiClientService pour
 * la génération du jeton (jamais stocké en clair) et EnsureValidApiKey pour
 * la résolution côté middleware.
 */
class ApiClient extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'token_hash',
        'is_sandbox',
        'is_active',
        'rate_limit_per_minute',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_sandbox' => 'boolean',
            'is_active' => 'boolean',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }
}
