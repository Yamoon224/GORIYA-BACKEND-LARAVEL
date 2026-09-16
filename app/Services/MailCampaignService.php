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
 * potentiels. L'envoi se fait en boucle synchrone (dispatchSync), dans la
 * requête HTTP elle-même : pas de queue, pas de worker — l'hébergement
 * mutualisé de goriya.net ne peut pas faire tourner `queue:work` en continu.
 * send() est donc rappelable telle quelle : un appel sur une campagne déjà
 * 'sending' reprend uniquement les destinataires encore PENDING, ce qui la
 * rend résiliente à un timeout PHP/serveur en cours de route (il suffit de
 * recliquer "Envoyer" pour continuer là où ça s'est arrêté).
 */
class MailCampaignService
{
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
        // Modèle jamais persisté : le hook HasUuid (creating) ne se déclenche
        // pas, donc l'id doit être posé à la main pour que le lien de
        // désabonnement (signedRoute) puisse être généré.
        $previewPartner->id = (string) Str::uuid();

        Mail::to($email)->send(new PartnerCampaignMail($campaign, $previewPartner));
    }

    /**
     * Résout les destinataires à partir des filtres (première fois seulement)
     * puis envoie à chaque destinataire PENDING en boucle, dans la requête
     * courante. Verrouille la campagne en 'sending' pour empêcher un double
     * envoi concurrent, mais un appel répété sur une campagne déjà 'sending'
     * est volontairement autorisé : il reprend les envois restants au lieu
     * d'échouer, pour couvrir le cas d'un timeout serveur en plein envoi.
     */
    public function send(MailCampaign $campaign, array $filters = []): MailCampaign
    {
        $campaign = DB::transaction(function () use ($campaign, $filters) {
            $campaign = MailCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($campaign->status === MailCampaignStatus::SENT) {
                throw new RuntimeException('Cette campagne a déjà été envoyée.');
            }

            if ($campaign->status === MailCampaignStatus::SENDING) {
                return $campaign;
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

            return $campaign->fresh();
        });

        // Hors transaction : l'envoi effectif peut être long (des minutes
        // pour plusieurs milliers de destinataires), on ne garde pas de
        // verrou DB pendant ce temps.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $pendingIds = $campaign->recipients()
            ->where('status', MailCampaignRecipientStatus::PENDING->value)
            ->pluck('id');

        foreach ($pendingIds as $recipientId) {
            SendCampaignRecipientJob::dispatchSync($recipientId);
        }

        return $campaign->fresh();
    }

    public function recipients(MailCampaign $campaign, int $page, int $limit): LengthAwarePaginator
    {
        return $campaign->recipients()
            ->with('partner:id,company_name,email')
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }
}
