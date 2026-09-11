<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\MailCampaignStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campagne de mailing envoyée aux partenaires potentiels (module Potentiels
 * Partenaires). Le contenu (subject/body_html) est entièrement rédigé et
 * modifiable par l'admin depuis le module — voir PartnerCampaignMail pour le
 * remplacement des placeholders à l'envoi.
 */
class MailCampaign extends Model
{
    use Auditable, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'subject',
        'body_html',
        'status',
        'target_filters',
        'total_recipients',
        'sent_count',
        'failed_count',
        'created_by',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MailCampaignStatus::class,
            'target_filters' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MailCampaignRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
