<?php

namespace App\Services;

use App\Enums\MailCampaignRecipientStatus;
use App\Enums\MailCampaignStatus;
use App\Jobs\SendCampaignRecipientJob;
use App\Mail\PartnerCampaignMail;
use App\Models\MailCampaign;
use App\Models\PotentialPartner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Création, édition et envoi des campagnes de mailing aux partenaires
 * potentiels. L'envoi étale les jobs dans le temps (voir self::SEND_INTERVAL_SECONDS)
 * plutôt que de tout pousser d'un coup : le mailer configuré (MAIL_MAILER/MAIL_HOST
 * dans .env) est une boîte SMTP mutualisée, pas un fournisseur transactionnel
 * dimensionné pour un envoi en masse instantané.
 */
class MailCampaignService
{
    /** Délai entre deux envois, en secondes — throttling volontaire. */
    private const SEND_INTERVAL_SECONDS = 3;

    public function __construct(
        private readonly PotentialPartnerService $partnerService,
    ) {}

    public function paginate(int $page, int $limit): LengthAwarePaginator
    {
        return MailCampaign::query()
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * @param  array{name: string, subject: string, bodyHtml: string, targetFilters?: array}  $data
     */
    public function create(array $data, ?string $createdBy): MailCampaign
    {
        return MailCampaign::create([
            'name' => $data['name'],
            'subject' => $data['subject'],
            'body_html' => $data['bodyHtml'],
            'target_filters' => $data['targetFilters'] ?? null,
            'status' => MailCampaignStatus::DRAFT->value,
            'created_by' => $createdBy,
        ]);
    }

    public function update(MailCampaign $campaign, array $data): MailCampaign
    {
        if ($campaign->status !== MailCampaignStatus::DRAFT) {
            throw new RuntimeException('Seule une campagne en brouillon peut être modifiée.');
        }

        $mapped = [];
        foreach (['name' => 'name', 'subject' => 'subject', 'bodyHtml' => 'body_html', 'targetFilters' => 'target_filters'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }

        $campaign->update($mapped);

        return $campaign->fresh();
    }

    public function delete(MailCampaign $campaign): void
    {
        if ($campaign->status === MailCampaignStatus::SENDING) {
            throw new RuntimeException('Une campagne en cours d\'envoi ne peut pas être supprimée.');
        }

        $campaign->delete();
    }

    /**
     * Envoie le rendu de la campagne (placeholders remplacés) à une adresse
     * de contrôle, sans toucher aux destinataires ni au statut — pour relire
     * le rendu avant un envoi réel.
     */
    public function testSend(MailCampaign $campaign, string $email): void
    {
        $previewPartner = new PotentialPartner([
            'company_name' => 'Entreprise Exemple',
            'contact_name' => 'Contact Exemple',
            'sector' => 'Secteur Exemple',
            'city' => 'Abidjan',
        ]);

        Mail::to($email)->send(new PartnerCampaignMail($campaign, $previewPartner));
    }

    /**
     * Résout les destinataires à partir des filtres, crée les lignes de suivi
     * et dispatche un job par destinataire avec un délai croissant. Verrouille
     * la campagne en 'sending' pour empêcher un double envoi.
     */
    public function send(MailCampaign $campaign, array $filters = []): MailCampaign
    {
        return DB::transaction(function () use ($campaign, $filters) {
            $campaign = MailCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($campaign->status !== MailCampaignStatus::DRAFT) {
                throw new RuntimeException('Cette campagne a déjà été envoyée ou est en cours d\'envoi.');
            }

            $recipients = $this->partnerService->reachable($filters);

            if ($recipients->isEmpty()) {
                throw new RuntimeException('Aucun destinataire joignable pour ces filtres.');
            }

            $now = now();
            $rows = $recipients->map(fn (PotentialPartner $p) => [
                'id' => (string) Str::uuid(),
                'mail_campaign_id' => $campaign->id,
                'potential_partner_id' => $p->id,
                'status' => MailCampaignRecipientStatus::PENDING->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('mail_campaign_recipients')->insertOrIgnore($rows);

            $campaign->update([
                'status' => MailCampaignStatus::SENDING->value,
                'target_filters' => $filters,
                'total_recipients' => count($rows),
            ]);

            foreach ($rows as $index => $row) {
                SendCampaignRecipientJob::dispatch($row['id'])
                    ->delay($now->clone()->addSeconds($index * self::SEND_INTERVAL_SECONDS));
            }

            return $campaign->fresh();
        });
    }

    public function recipients(MailCampaign $campaign, int $page, int $limit): LengthAwarePaginator
    {
        return $campaign->recipients()
            ->with('partner:id,company_name,email')
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }
}
