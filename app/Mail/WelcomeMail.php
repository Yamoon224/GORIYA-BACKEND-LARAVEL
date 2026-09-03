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
 * Email de bienvenue envoyé une seule fois, au moment où l'inscription est
 * réellement aboutie (email vérifié par OTP, ou compte créé via Google/
 * LinkedIn qui court-circuite l'OTP). Voir WelcomeEmailService.
 *
 * Le contenu dépend du rôle : un recruteur n'a pas de CV à compléter, un
 * candidat n'a pas d'offres à publier.
 */
class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
    ) {}

    private function isEnterprise(): bool
    {
        return $this->user->role === UserRole::ENTERPRISE;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isEnterprise()
                ? 'Bienvenue sur Goriya — votre espace entreprise est actif !'
                : 'Bienvenue sur Goriya — ton compte est prêt !',
        );
    }

    public function content(): Content
    {
        $frontendUrl = $this->isEnterprise()
            ? (string) config('app.enterprise_frontend_url')
            : (string) config('app.frontend_url');

        return new Content(
            view: 'emails.welcome',
            with: [
                'name' => $this->user->name,
                'logoUrl' => ((string) config('app.frontend_url')).'/images/logo-blanc.png',
                'title' => 'Bienvenue sur Goriya !',
                'badge' => 'Inscription confirmée',
                'paragraphs' => $this->isEnterprise()
                    ? [
                        'Votre espace entreprise est actif. Vous pouvez dès maintenant publier vos offres, suivre les candidatures reçues et rechercher les profils qui vous intéressent.',
                        'Prenez un moment pour compléter la page de votre entreprise : logo, secteur et présentation sont les premiers éléments que consultent les candidats.',
                    ]
                    : [
                        'Ton compte Goriya est actif. Tu peux dès maintenant compléter ton profil, importer ton CV et postuler aux offres qui te correspondent.',
                        "Prends un moment pour vérifier ton profil, puis n'oublie pas d'indiquer aux recruteurs que tu es disponible pour laisser les opportunités venir à toi !",
                    ],
                'tip' => $this->isEnterprise()
                    ? 'Astuce : les offres publiées par une entreprise au profil complet reçoivent nettement plus de candidatures. Votre page reste modifiable à tout moment.'
                    : 'Astuce : un profil complet (expérience, compétences, formation) est mis en avant auprès des recruteurs. Ton profil reste modifiable à tout moment.',
                'ctaLabel' => $this->isEnterprise() ? 'Accéder à mon espace' : 'Compléter mon profil',
                'ctaUrl' => $this->isEnterprise() ? $frontendUrl.'/dashboard' : $frontendUrl.'/profil',
                'privacyUrl' => ((string) config('app.frontend_url')).'/confidentialite',
            ],
        );
    }
}
