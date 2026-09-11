<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\PotentialPartnerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entreprise ciblée pour une campagne de partenariat (module Potentiels
 * Partenaires), qu'elle vienne d'un import en masse (voir la commande
 * `partners:import`) ou d'une saisie manuelle par un admin.
 */
class PotentialPartner extends Model
{
    use Auditable, HasUuid;

    /**
     * raw_data peut peser plusieurs Ko par ligne importée — exclu du journal
     * d'audit pour ne pas le saturer sur un import en masse.
     */
    protected array $auditExcludes = ['raw_data'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ncc',
        'company_name',
        'email',
        'email_valid',
        'contact_name',
        'contact_phone',
        'sector',
        'activity_label',
        'city',
        'commune',
        'address',
        'company_size',
        'ca_tranche',
        'workforce_tranche',
        'first_exercise_year',
        'status',
        'source',
        'notes',
        'raw_data',
        'last_contacted_at',
        'unsubscribed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_valid' => 'boolean',
            'status' => PotentialPartnerStatus::class,
            'raw_data' => 'array',
            'last_contacted_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(MailCampaignRecipient::class);
    }

    public function isReachable(): bool
    {
        return $this->email_valid
            && $this->unsubscribed_at === null
            && $this->status !== PotentialPartnerStatus::DO_NOT_CONTACT;
    }
}
