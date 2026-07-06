<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;

/**
 * Mirroir du stub de paramètres système/email in-memory de backend/src/admin/
 * admin-platform.service.ts — pas de table dédiée côté source, persistance
 * via Cache. Extrait de l'ex-AdminPlatformService.
 */
class AdminSettingsService
{
    private const DEFAULT_SYSTEM_SETTINGS = [
        'platformName' => 'GORIYA',
        'mainUrl' => 'https://goriya-admin.vercel.app',
        'supportEmail' => 'support@goriya.app',
        'timezone' => 'Africa/Abidjan',
        'description' => 'Administration de la plateforme Goriya',
        'maintenanceMode' => false,
        'maxUploadSize' => 10,
        'allowedFileTypes' => ['pdf', 'doc', 'docx'],
        'smtpHost' => 'smtp.example.com',
        'smtpPort' => 587,
        'smtpUser' => 'noreply@goriya.app',
        'senderName' => 'GORIYA',
        'senderEmail' => 'noreply@goriya.app',
    ];

    public function getSystemSettings(): array
    {
        return Cache::rememberForever('admin:system_settings', fn () => self::DEFAULT_SYSTEM_SETTINGS);
    }

    public function updateSystemSettings(array $settings): array
    {
        $merged = array_merge($this->getSystemSettings(), $settings);
        Cache::forever('admin:system_settings', $merged);

        return $merged;
    }

    public function getEmailSettings(): array
    {
        $settings = $this->getSystemSettings();

        return [
            'smtpHost' => $settings['smtpHost'],
            'smtpPort' => $settings['smtpPort'],
            'smtpUser' => $settings['smtpUser'],
            'senderName' => $settings['senderName'],
            'senderEmail' => $settings['senderEmail'],
        ];
    }

    public function updateEmailSettings(array $settings): void
    {
        $merged = array_merge($this->getSystemSettings(), $settings);
        Cache::forever('admin:system_settings', $merged);
    }

    public function testEmailSettings(): array
    {
        return ['success' => true, 'message' => 'Configuration email validee'];
    }
}
