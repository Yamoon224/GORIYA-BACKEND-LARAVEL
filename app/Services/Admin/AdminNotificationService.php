<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mirroir du stub de notifications in-memory de backend/src/admin/
 * admin-platform.service.ts — pas de table dédiée côté source, persistance
 * via Cache. Extrait de l'ex-AdminPlatformService.
 */
class AdminNotificationService
{
    private const DEFAULT_NOTIFICATION_SETTINGS = [
        'applications' => true,
        'emplois' => true,
        'recommandations' => true,
    ];

    public function getNotifications(): array
    {
        return Cache::rememberForever('admin:notifications', fn () => [
            ['id' => (string) Str::uuid(), 'title' => "Bienvenue sur l'admin Goriya", 'read' => false, 'createdAt' => now()->toJSON()],
        ]);
    }

    public function markNotificationAsRead(string $notificationId): void
    {
        $notifications = $this->getNotifications();

        foreach ($notifications as &$notification) {
            if ($notification['id'] === $notificationId) {
                $notification['read'] = true;
            }
        }

        Cache::forever('admin:notifications', $notifications);
    }

    public function markAllNotificationsAsRead(): void
    {
        $notifications = $this->getNotifications();

        foreach ($notifications as &$notification) {
            $notification['read'] = true;
        }

        Cache::forever('admin:notifications', $notifications);
    }

    public function updateNotificationSettings(array $settings): void
    {
        $current = Cache::rememberForever('admin:notification_settings', fn () => self::DEFAULT_NOTIFICATION_SETTINGS);
        Cache::forever('admin:notification_settings', array_merge($current, $settings));
    }
}
