<?php

namespace App\Services;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envoi de l'email de bienvenue, juste après une inscription aboutie.
 *
 * « Aboutie » et non « soumise » : le compte créé par POST /users ne permet
 * pas encore de se connecter (OtpLoginGate — 403 EMAIL_NOT_VERIFIED tant que
 * l'email n'est pas vérifié). Envoyer la bienvenue à ce moment-là doublerait
 * le mail d'OTP et son bouton mènerait à une connexion impossible. Le mail
 * part donc au moment exact où le compte devient utilisable :
 *   - vérification du code OTP (OtpService::verify), inscription email ;
 *   - création du compte via Google/LinkedIn (AuthService), sans OTP.
 *
 * Ces deux points ne se produisent qu'une fois par compte, d'où l'absence de
 * colonne « welcome_email_sent_at » : la transition non-vérifié → vérifié
 * fait office de garde.
 */
class WelcomeEmailService
{
    /**
     * Un échec SMTP ne doit jamais faire échouer l'inscription elle-même :
     * le compte est créé et exploitable, seul le mail de courtoisie manque.
     */
    public function send(User $user): void
    {
        try {
            Mail::to($user->email)->send(new WelcomeMail($user));
        } catch (\Throwable $e) {
            Log::error('Envoi de l\'email de bienvenue échoué', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
