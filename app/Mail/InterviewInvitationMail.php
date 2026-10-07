<?php

namespace App\Mail;

use App\Enums\RecruitmentInterviewType;
use App\Mail\Concerns\HasContactFooter;
use App\Models\RecruitmentInterview;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Convocation à un entretien de recrutement, envoyée au candidat quel que soit
 * son forfait : un entretien planifié, déplacé ou annulé ne peut pas dépendre
 * du seul centre de notifications. Un fichier .ics l'ajoute à son agenda (ou
 * l'en retire quand l'entretien est annulé).
 *
 * L'heure est donnée à l'heure d'Abidjan, celle des entreprises de la
 * plateforme — comme la notification in-app (NotificationService).
 *
 * Réutilise le gabarit générique `emails.welcome`.
 */
class InterviewInvitationMail extends Mailable
{
    use HasContactFooter, Queueable, SerializesModels;

    public const SCHEDULED = 'scheduled';

    public const RESCHEDULED = 'rescheduled';

    public const CANCELLED = 'cancelled';

    private const TIMEZONE = 'Africa/Abidjan';

    public function __construct(
        public readonly RecruitmentInterview $interview,
        public readonly string $kind = self::SCHEDULED,
    ) {}

    public function envelope(): Envelope
    {
        $label = match ($this->kind) {
            self::RESCHEDULED => 'Entretien déplacé',
            self::CANCELLED => 'Entretien annulé',
            default => 'Entretien programmé',
        };

        return new Envelope(subject: "{$label} : {$this->jobTitle()} - {$this->companyName()}");
    }

    public function content(): Content
    {
        $publicUrl = rtrim((string) config('app.frontend_url'), '/');
        $name = $this->interview->candidature?->candidate_name ?? '';
        $cancelled = $this->kind === self::CANCELLED;

        return new Content(
            view: 'emails.welcome',
            with: [
                'name' => $name,
                'greeting' => $name !== '' ? "Bonjour {$name}," : 'Bonjour,',
                'logoUrl' => $publicUrl.'/images/logo-blanc.png',
                'headerImageUrl' => $publicUrl.'/images/email-welcome-header.png',
                'title' => $this->jobTitle(),
                'badge' => $cancelled ? 'Entretien annulé' : 'Convocation à un entretien',
                'paragraphs' => $cancelled ? $this->cancellationLines() : $this->invitationLines(),
                'tip' => $cancelled
                    ? "Ta candidature reste suivie dans ton espace Goriya : l'entreprise peut te proposer un nouveau créneau."
                    : ($this->isVideo()
                        ? "Le jour J, connecte-toi à ton compte Goriya puis clique sur le bouton ci-dessous : la salle t'attend dans GORIYA Meet."
                        : "Un empêchement ? Préviens l'entreprise depuis la messagerie de ton espace Goriya."),
                'ctaLabel' => ! $cancelled && $this->isVideo() ? "Rejoindre l'entretien" : 'Voir ma candidature',
                'ctaUrl' => $this->actionUrl(),
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
        $method = $this->kind === self::CANCELLED ? 'CANCEL' : 'PUBLISH';

        return [
            Attachment::fromData(fn () => $this->ics($method), 'entretien.ics')->withMime("text/calendar; charset=utf-8; method={$method}"),
        ];
    }

    /** @return list<string> */
    private function invitationLines(): array
    {
        $interview = $this->interview;
        $intro = $this->kind === self::RESCHEDULED
            ? "{$this->companyName()} a déplacé ton entretien pour le poste « {$this->jobTitle()} »."
            : "{$this->companyName()} te propose un entretien {$interview->type->label()} pour le poste « {$this->jobTitle()} ».";

        return array_values(array_filter([
            $intro,
            'Date : '.$this->formattedDate(),
            'Durée prévue : '.($interview->duration_minutes ?? 45).' minutes',
            ! $this->isVideo() && $interview->location
                ? ($interview->type === RecruitmentInterviewType::PHONE ? 'Contact : ' : 'Lieu : ').$interview->location
                : null,
            $interview->interviewers ? 'Tes interlocuteurs : '.$interview->interviewers : null,
            $interview->description ? "Message de l'entreprise : ".$interview->description : null,
        ]));
    }

    /** @return list<string> */
    private function cancellationLines(): array
    {
        return [
            "Ton entretien du {$this->formattedDate()} pour le poste « {$this->jobTitle()} » chez {$this->companyName()} est annulé.",
        ];
    }

    private function isVideo(): bool
    {
        return $this->interview->call_session_id !== null;
    }

    private function actionUrl(): string
    {
        $publicUrl = rtrim((string) config('app.frontend_url'), '/');

        return $this->isVideo() && $this->kind !== self::CANCELLED
            ? $publicUrl.'/appels?rejoindre='.$this->interview->call_session_id
            : $publicUrl.'/mes-offres';
    }

    private function jobTitle(): string
    {
        return $this->interview->candidature?->jobOffer?->title ?? 'Entretien de recrutement';
    }

    private function companyName(): string
    {
        return $this->interview->candidature?->jobOffer?->company?->name ?? "L'entreprise";
    }

    private function formattedDate(): string
    {
        return $this->interview->scheduled_at->setTimezone(self::TIMEZONE)->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH[h]mm').' (heure d\'Abidjan, GMT)';
    }

    /** Événement d'agenda lisible par Google Agenda, Outlook et Apple Calendrier. */
    private function ics(string $method): string
    {
        $interview = $this->interview;
        $start = $interview->scheduled_at->utc();
        $end = $start->addMinutes($interview->duration_minutes ?? 45);
        $escape = fn (string $value) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
        $where = $this->isVideo() ? 'GORIYA Meet' : (string) ($interview->location ?? '');

        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Goriya//Recrutement//FR',
            'METHOD:'.$method,
            'BEGIN:VEVENT',
            // UID stable : un entretien déplacé met à jour l'événement existant.
            'UID:entretien-'.$interview->id.'@goriya.net',
            'SEQUENCE:'.now()->getTimestamp(),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            'SUMMARY:'.$escape("Entretien {$this->jobTitle()} - {$this->companyName()}"),
            'DESCRIPTION:'.$escape(trim(($interview->description ? $interview->description."\n\n" : '').$this->actionUrl())),
            $where !== '' ? 'LOCATION:'.$escape($where) : null,
            'URL:'.$this->actionUrl(),
            'STATUS:'.($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
            'ORGANIZER;CN='.$escape($this->companyName()).':mailto:'.config('mail.from.address'),
            'END:VEVENT',
            'END:VCALENDAR',
        ]))."\r\n";
    }
}
