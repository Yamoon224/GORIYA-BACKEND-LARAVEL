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
 * (vérification d'inscription candidat/entreprise, admin).
 */
class OtpService
{
    private const EXPIRY_MINUTES = 10;

    private const RESEND_COOLDOWN_SECONDS = 45;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function send(User $user, string $purpose = 'EMAIL_VERIFICATION'): void
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

        Mail::to($user->email)->send(new OtpMail($user, $code, self::EXPIRY_MINUTES));
    }

    public function verify(string $email, string $code, string $purpose = 'EMAIL_VERIFICATION'): User
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

        if ($purpose === 'EMAIL_VERIFICATION' && ! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}
