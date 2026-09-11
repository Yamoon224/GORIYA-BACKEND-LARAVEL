<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\MailCampaignRecipientStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statut d'envoi d'une campagne pour un partenaire potentiel donné. Pas
 * d'Auditable ici : le volume (un enregistrement par destinataire et par
 * campagne) rendrait le journal d'audit inexploitable ; le statut global de
 * la campagne (MailCampaign) est déjà audité.
 */
class MailCampaignRecipient extends Model
{
    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'mail_campaign_id',
        'potential_partner_id',
        'status',
        'error_message',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MailCampaignRecipientStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MailCampaign::class, 'mail_campaign_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PotentialPartner::class, 'potential_partner_id');
    }
}
