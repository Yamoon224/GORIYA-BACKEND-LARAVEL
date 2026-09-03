<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Génération/envoi et vérification des codes OTP par email — remplace le
 * stub historique (AdminAuthService::verifyOtp acceptait n'importe quel
 * code non vide). Un seul mécanisme, réutilisé par tous les flux
 * (vérification d'inscription candidat/entreprise, admin, réinitialisation
 * de mot de passe).
 */
class OtpService
{
    public const PURPOSE_EMAIL_VERIFICATION = 'EMAIL_VERIFICATION';

    public const PURPOSE_PASSWORD_RESET = 'PASSWORD_RESET';

    private const EXPIRY_MINUTES = 10;

    private const RESEND_COOLDOWN_SECONDS = 45;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly WelcomeEmailService $welcomeEmailService,
    ) {}

    public function send(User $user, string $purpose = self::PURPOSE_EMAIL_VERIFICATION): void
    {
        $recent = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        if ($recent && $recent->created_at && $recent->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            abort(429, 'Merci de patienter avant de redemander un code.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ]);

        Mail::to($user->email)->send(new OtpMail($user, $code, self::EXPIRY_MINUTES, $purpose));
    }

    public function verify(string $email, string $code, string $purpose = self::PURPOSE_EMAIL_VERIFICATION): User
    {
        $user = $this->userRepository->findByEmail($email);
        if (! $user) {
            abort(404, 'Utilisateur introuvable');
        }

        $otp = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('code', $code)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if (! $otp) {
            abort(401, 'Code OTP invalide ou expiré');
        }

        $otp->update(['consumed_at' => now()]);

        // Première vérification de l'email = inscription aboutie : c'est ici,
        // et une seule fois par compte, que part l'email de bienvenue.
        if ($purpose === self::PURPOSE_EMAIL_VERIFICATION && ! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->welcomeEmailService->send($user);
        }

        return $user;
    }

    /**
     * Consomme tous les codes encore valides d'un usage donné. Appelé après
     * une réinitialisation réussie : un code émis avant le changement de mot
     * de passe ne doit plus permettre d'en imposer un second.
     */
    public function invalidatePending(User $user, string $purpose): void
    {
        OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
