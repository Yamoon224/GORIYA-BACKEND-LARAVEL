<?php

namespace App\Mail;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Doublage email d'une notification in-app, réservé aux forfaits à
 * `notification_level: ELEVE` (Standard, Premium, Business+ — voir
 * SubscriptionPlanSeeder et NotificationService::shouldEmail()). Les autres
 * forfaits (Grouilleur, Business) restent en notification "Faible" : in-app
 * uniquement, cet email n'est jamais envoyé.
 *
 * Réutilise le gabarit générique `emails.welcome` (déjà éprouvé pour l'email
 * de bienvenue et l'embauche) plutôt que d'écrire un nouveau template.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $notificationTitle,
        public readonly string $body,
        public readonly ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notificationTitle);
    }

    public function content(): Content
    {
        $isEnterprise = $this->user->role === UserRole::ENTERPRISE;
        $publicUrl = (string) config('app.frontend_url');
        $appUrl = $isEnterprise ? (string) config('app.enterprise_frontend_url') : $publicUrl;
        $ctaUrl = rtrim($appUrl, '/').($this->link ?? '/');

        return new Content(
            view: 'emails.welcome',
            with: [
                'name' => $this->user->name,
                'logoUrl' => $publicUrl.'/images/logo-blanc.png',
                'headerImageUrl' => $publicUrl.'/images/email-welcome-header.jpg',
                'title' => $this->notificationTitle,
                'badge' => 'Notification prioritaire',
                'paragraphs' => [$this->body],
                'tip' => 'Astuce : cette notification t\'est envoyée aussi par email car ton forfait inclut les notifications prioritaires.',
                'ctaLabel' => 'Voir sur Goriya',
                'ctaUrl' => $ctaUrl,
                'privacyUrl' => $publicUrl.'/confidentialite',
            ],
        );
    }
}
