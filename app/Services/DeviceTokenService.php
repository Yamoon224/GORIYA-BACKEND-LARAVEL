<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;

/**
 * Enregistrement des jetons push (app mobile) — voir
 * PushNotificationServiceInterface pour l'envoi effectif.
 */
class DeviceTokenService
{
    public function register(User $user, string $token, string $platform): DeviceToken
    {
        return DeviceToken::updateOrCreate(
            ['user_id' => $user->id, 'token' => $token],
            ['platform' => $platform],
        );
    }

    public function unregister(User $user, string $token): void
    {
        DeviceToken::where('user_id', $user->id)->where('token', $token)->delete();
    }

    /**
     * @return array<int, string>
     */
    public function tokensFor(User $user): array
    {
        return DeviceToken::where('user_id', $user->id)->pluck('token')->all();
    }
}
