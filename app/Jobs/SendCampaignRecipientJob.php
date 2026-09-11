<?php

namespace App\Jobs;

use App\Enums\MailCampaignRecipientStatus;
use App\Enums\MailCampaignStatus;
use App\Mail\PartnerCampaignMail;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie une campagne à un seul destinataire. Dispatché avec un délai croissant
 * par MailCampaignService::send() pour étaler les envois dans le temps — le
 * mailer Hostinger de goriya.net est une boîte partagée, pas un fournisseur
 * transactionnel dimensionné pour un envoi en masse instantané (voir
 * [[smtp_mail_config]]).
 */
class SendCampaignRecipientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $recipientId) {}

    public function handle(): void
    {
        $recipient = MailCampaignRecipient::with(['campaign', 'partner'])->find($this->recipientId);

        if (! $recipient || $recipient->status !== MailCampaignRecipientStatus::PENDING) {
            return;
        }

        $partner = $recipient->partner;
        $campaign = $recipient->campaign;

        if (! $partner || ! $campaign || ! $partner->isReachable()) {
            $recipient->update(['status' => MailCampaignRecipientStatus::SKIPPED]);
            $this->bumpCampaignCounters($campaign?->id, skipped: true);

            return;
        }

        try {
            Mail::to($partner->email)->send(new PartnerCampaignMail($campaign, $partner));

            $recipient->update(['status' => MailCampaignRecipientStatus::SENT, 'sent_at' => now()]);
            $partner->update(['last_contacted_at' => now()]);
            $this->bumpCampaignCounters($campaign->id, sent: true);
        } catch (Throwable $e) {
            Log::error('Campaign email failed: '.$e->getMessage(), ['recipient_id' => $recipient->id]);
            $recipient->update([
                'status' => MailCampaignRecipientStatus::FAILED,
                'error_message' => $e->getMessage(),
            ]);
            $this->bumpCampaignCounters($campaign->id, failed: true);
        }
    }

    /**
     * Incrémente les compteurs de la campagne et la clôture (SENT) une fois
     * que tous les destinataires ont été traités (sent + failed + skipped).
     */
    private function bumpCampaignCounters(?string $campaignId, bool $sent = false, bool $failed = false, bool $skipped = false): void
    {
        if (! $campaignId) {
            return;
        }

        DB::transaction(function () use ($campaignId, $sent, $failed) {
            $campaign = MailCampaign::whereKey($campaignId)->lockForUpdate()->first();

            if (! $campaign) {
                return;
            }

            if ($sent) {
                $campaign->increment('sent_count');
            }
            if ($failed) {
                $campaign->increment('failed_count');
            }

            $processed = $campaign->fresh()->recipients()
                ->whereIn('status', [
                    MailCampaignRecipientStatus::SENT,
                    MailCampaignRecipientStatus::FAILED,
                    MailCampaignRecipientStatus::SKIPPED,
                ])
                ->count();

            if ($processed >= $campaign->total_recipients) {
                $campaign->update(['status' => MailCampaignStatus::SENT, 'sent_at' => $campaign->sent_at ?? now()]);
            }
        });
    }
}
