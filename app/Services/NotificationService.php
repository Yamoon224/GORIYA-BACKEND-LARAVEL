<?php

namespace App\Services;

use App\Enums\CandidatureStatus;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Candidature;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Notifications réelles par utilisateur — remplace le stub Cache global de
 * App\Services\Admin\AdminNotificationService pour les utilisateurs normaux
 * (candidats/entreprises), qui restent hors du périmètre /admin/notifications.
 */
class NotificationService
{
    private const DEFAULT_SETTINGS = [
        'applications' => true,
        'emplois' => true,
        'recommandations' => true,
    ];

    public function listFor(User $user): Collection
    {
        return Notification::where('user_id', $user->id)->orderByDesc('created_at')->get();
    }

    public function markAsRead(Notification $notification): void
    {
        $notification->update(['is_read' => true, 'read_at' => now()]);
    }

    public function markAllAsRead(User $user): void
    {
        Notification::where('user_id', $user->id)->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    public function delete(Notification $notification): void
    {
        $notification->delete();
    }

    /**
     * @param  array{applications?: bool, emplois?: bool, recommandations?: bool}  $settings
     */
    public function updateSettings(User $user, array $settings): void
    {
        $current = Cache::rememberForever("notification_settings:{$user->id}", fn () => self::DEFAULT_SETTINGS);
        Cache::forever("notification_settings:{$user->id}", array_merge($current, $settings));
    }

    public function notifyNewMessage(User $recipient, Conversation $conversation, string $preview): void
    {
        Notification::create([
            'user_id' => $recipient->id,
            'type' => NotificationType::MESSAGE,
            'title' => 'Nouveau message',
            'body' => mb_strimwidth($preview, 0, 140, '…'),
        ]);
    }

    public function notifyApplicationStatusChanged(Candidature $candidature): void
    {
        $labels = [
            CandidatureStatus::EN_COURS->value => 'est en cours de traitement',
            CandidatureStatus::APPROUVEE->value => 'a été acceptée',
            CandidatureStatus::REJETEE->value => 'a été refusée',
        ];

        $statusValue = $candidature->status instanceof CandidatureStatus
            ? $candidature->status->value
            : $candidature->status;

        $label = $labels[$statusValue] ?? 'a été mise à jour';

        Notification::create([
            'user_id' => $candidature->user_id,
            'type' => NotificationType::APPLICATION_STATUS,
            'title' => 'Candidature mise à jour',
            'body' => "Ta candidature pour \"{$candidature->jobOffer?->title}\" {$label}.",
        ]);
    }

    public function notifyNewApplication(Candidature $candidature): void
    {
        $company = $candidature->jobOffer?->company;
        if (! $company) {
            return;
        }

        $recipients = User::where('company_id', $company->id)
            ->where('role', UserRole::ENTERPRISE)
            ->get();

        foreach ($recipients as $recipient) {
            Notification::create([
                'user_id' => $recipient->id,
                'type' => NotificationType::APPLICATION_STATUS,
                'title' => 'Nouvelle candidature',
                'body' => "{$candidature->candidate_name} a postulé à \"{$candidature->jobOffer?->title}\".",
            ]);
        }
    }
}
