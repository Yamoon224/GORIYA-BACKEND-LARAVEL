<?php

namespace App\Mail;

use App\Mail\Concerns\HasContactFooter;
use App\Models\CallSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation à une session GORIYA Meet, envoyée à chaque adresse conviée par
 * l'organisateur (membre de Goriya ou non). Quand l'appel est planifié, un
 * fichier .ics est joint pour l'ajouter à son agenda, comme le fait Google
 * Meet.
 *
 * Réutilise le gabarit générique `emails.welcome`.
 */
class CallInvitationMail extends Mailable
{
    use HasContactFooter, Queueable, SerializesModels;

    public function __construct(
        public readonly CallSession $session,
        public readonly string $hostName,
        public readonly ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invitation : {$this->session->title} - GORIYA Meet");
    }

    public function content(): Content
    {
        $publicUrl = rtrim((string) config('app.frontend_url'), '/');
        $when = $this->session->scheduled_at
            ? 'Date : '.$this->formattedDate()
            : "L'appel peut démarrer dès maintenant.";

        return new Content(
            view: 'emails.welcome',
            with: [
                'name' => $this->recipientName ?? '',
                'greeting' => $this->recipientName ? "Bonjour {$this->recipientName}," : 'Bonjour,',
                'logoUrl' => $publicUrl.'/images/logo-blanc.png',
                'headerImageUrl' => $publicUrl.'/images/email-welcome-header.jpg',
                'title' => $this->session->title,
                'badge' => 'Invitation à un appel vidéo',
                'paragraphs' => array_values(array_filter([
                    "{$this->hostName} vous invite à un appel vidéo sur GORIYA Meet.",
                    $when,
                    $this->session->description ? "Message de l'organisateur : {$this->session->description}" : null,
                ])),
                'tip' => "Pour rejoindre l'appel, connectez-vous à votre compte Goriya (ou créez-en un gratuitement avec cette adresse email), puis cliquez sur le bouton ci-dessous.",
                'ctaLabel' => "Rejoindre l'appel",
                'ctaUrl' => $this->joinUrl(),
                'privacyUrl' => $publicUrl.'/confidentialite',
                ...$this->contactFooterData(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->session->scheduled_at) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->ics(), 'invitation.ics')->withMime('text/calendar; charset=utf-8; method=PUBLISH'),
        ];
    }

    private function joinUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/appels?rejoindre='.$this->session->id;
    }

    private function formattedDate(): string
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $date = $this->session->scheduled_at->copy()->timezone($timezone)->locale('fr');

        return $date->isoFormat('dddd D MMMM YYYY [à] HH[h]mm').' ('.($timezone === 'UTC' ? 'heure GMT' : $timezone).')';
    }

    /** Événement d'agenda d'une heure, lisible par Google Agenda, Outlook et Apple Calendrier. */
    private function ics(): string
    {
        $start = $this->session->scheduled_at->copy()->utc();
        $escape = fn (string $value) => str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
        $description = trim(($this->session->description ? $this->session->description."\n\n" : '')."Rejoindre l'appel : ".$this->joinUrl());

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Goriya//GORIYA Meet//FR',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$this->session->id.'@goriya.net',
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$start->copy()->addHour()->format('Ymd\THis\Z'),
            'SUMMARY:'.$escape($this->session->title),
            'DESCRIPTION:'.$escape($description),
            'LOCATION:'.$escape('GORIYA Meet'),
            'URL:'.$this->joinUrl(),
            'ORGANIZER;CN='.$escape($this->hostName).':mailto:'.config('mail.from.address'),
            'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n";
    }
}
