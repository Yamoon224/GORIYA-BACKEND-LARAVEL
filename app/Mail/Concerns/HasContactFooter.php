<?php

namespace App\Mail\Concerns;

/**
 * Bloc de contact (email, téléphone, liens candidat/entreprise) affiché en
 * bas de tous les emails du système — voir emails.welcome, emails.otp et
 * emails.partner-campaign.
 */
trait HasContactFooter
{
    /**
     * @return array<string, string>
     */
    private function contactFooterData(): array
    {
        return [
            'contactEmail' => 'support@goriya.net',
            'contactPhone' => '+225 07 58 70 06 92',
            'candidateUrl' => (string) config('app.frontend_url'),
            'enterpriseUrl' => (string) config('app.enterprise_frontend_url'),
        ];
    }
}
