<?php

namespace App\Mail;

use App\Models\MailCampaign;
use App\Models\PotentialPartner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Email d'une campagne de prospection (module Potentiels Partenaires). Le
 * sujet et le corps sont entièrement rédigés/édités par l'admin sur la
 * campagne (MailCampaign::subject / body_html) — cette classe se contente de
 * remplacer les placeholders et d'ajouter le lien de désabonnement, commun à
 * tous les envois quel que soit le contenu rédigé.
 */
class PartnerCampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly MailCampaign $campaign,
        public readonly PotentialPartner $partner,
    ) {}

    /**
     * @return array<string, string>
     */
    private function placeholders(): array
    {
        return [
            '{{entreprise}}' => $this->partner->company_name,
            '{{contact}}' => $this->partner->contact_name ?: $this->partner->company_name,
            '{{secteur}}' => $this->partner->sector ?: '',
            '{{ville}}' => $this->partner->city ?: '',
        ];
    }

    private function render(string $text): string
    {
        return strtr($text, $this->placeholders());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->render($this->campaign->subject),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.partner-campaign',
            with: [
                'logoUrl' => ((string) config('app.frontend_url')).'/images/logo-blanc.png',
                'bodyHtml' => $this->render($this->campaign->body_html),
                'unsubscribeUrl' => URL::signedRoute('partners.unsubscribe', ['partner' => $this->partner->id]),
            ],
        );
    }
}
