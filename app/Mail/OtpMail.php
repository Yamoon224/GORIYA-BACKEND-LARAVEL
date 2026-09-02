<?php

namespace App\Mail;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $code,
        public readonly int $validMinutes = 10,
        public readonly string $purpose = OtpService::PURPOSE_EMAIL_VERIFICATION,
    ) {}

    private function isPasswordReset(): bool
    {
        return $this->purpose === OtpService::PURPOSE_PASSWORD_RESET;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isPasswordReset()
                ? 'Réinitialisation de ton mot de passe Goriya'
                : 'Votre code de vérification Goriya',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'name' => $this->user->name,
                'code' => $this->code,
                'validMinutes' => $this->validMinutes,
                'title' => $this->isPasswordReset()
                    ? 'Réinitialisation de ton mot de passe Goriya'
                    : 'Votre code de vérification Goriya',
                'intro' => $this->isPasswordReset()
                    ? "Voici le code à saisir pour choisir un nouveau mot de passe. Il expire dans {$this->validMinutes} minutes."
                    : "Voici ton code de vérification. Il expire dans {$this->validMinutes} minutes.",
                'footer' => $this->isPasswordReset()
                    ? "Si tu n'es pas à l'origine de cette demande, ignore cet email : ton mot de passe reste inchangé."
                    : "Si tu n'es pas à l'origine de cette demande, tu peux ignorer cet email.",
            ],
        );
    }
}
