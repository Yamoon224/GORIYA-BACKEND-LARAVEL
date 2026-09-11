<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Envoyé quand une fiche employé est créée — qu'elle vienne d'une candidature
 * Goriya acceptée ou d'une saisie manuelle RH. Réutilise le gabarit générique
 * `emails.welcome` (déjà éprouvé pour l'email de bienvenue) plutôt que
 * d'écrire un nouveau template.
 *
 * Le bouton d'action diffère selon que le destinataire a un compte Goriya
 * (embauche depuis une candidature : `Employee::user_id` est renseigné) ou
 * non (saisie manuelle : on l'invite à en créer un pour accéder à son futur
 * espace employé).
 */
class EmployeeHiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Employee $employee,
        public readonly Company $company,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Vous avez rejoint l'équipe de {$this->company->name} sur Goriya",
        );
    }

    public function content(): Content
    {
        $frontendUrl = (string) config('app.frontend_url');
        $hasAccount = $this->employee->user_id !== null;

        return new Content(
            view: 'emails.welcome',
            with: [
                'name' => $this->employee->first_name,
                'logoUrl' => $frontendUrl.'/images/logo-blanc.png',
                'headerImageUrl' => $frontendUrl.'/images/email-welcome-header.jpg',
                'title' => 'Bienvenue dans l\'équipe !',
                'badge' => 'Embauche confirmée',
                'paragraphs' => [
                    "{$this->company->name} vient de créer ta fiche employé pour le poste de {$this->employee->job_title}, à compter du ".$this->employee->hire_date->locale('fr')->isoFormat('D MMMM YYYY').'.',
                    $hasAccount
                        ? "Depuis ton espace Goriya, tu peux désormais suivre tes congés et adresser tes demandes RH (attestations, avances, formations…) directement à ton entreprise."
                        : "Pour suivre tes congés et adresser tes demandes RH directement depuis Goriya, crée ton compte avec cette adresse e-mail.",
                ],
                'tip' => 'Astuce : ton espace employé reste accessible tant que ta fiche est active chez cet employeur.',
                'ctaLabel' => $hasAccount ? 'Accéder à mon espace employé' : 'Créer mon compte Goriya',
                'ctaUrl' => $hasAccount
                    ? $frontendUrl.'/espace-employe'
                    : $frontendUrl.'/auth/signup?email='.urlencode((string) $this->employee->email),
                'privacyUrl' => $frontendUrl.'/confidentialite',
            ],
        );
    }
}
