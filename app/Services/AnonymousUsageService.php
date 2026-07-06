<?php

namespace App\Services;

use App\Repositories\Contracts\AnonymousUsageRepositoryInterface;

/**
 * Mirroir de backend/src/anonymous-usage — quota d'usage gratuit avant
 * inscription, identifié par deviceId. Extrait de AnonymousUsageController
 * pour cohérence avec le reste du port.
 */
class AnonymousUsageService
{
    private const FREE_LIMITS = [
        'cv_analysis' => 3,
    ];

    public function __construct(private readonly AnonymousUsageRepositoryInterface $anonymousUsageRepository) {}

    /**
     * Vérifie et consomme atomiquement une utilisation gratuite. À appeler
     * juste avant d'exécuter la fonctionnalité.
     *
     * @return array{allowed: bool, used: int, remaining: int, limit: int}
     */
    public function consume(string $deviceId, string $featureKey): array
    {
        $limit = self::FREE_LIMITS[$featureKey] ?? 3;

        $record = $this->anonymousUsageRepository->findOrNew($deviceId, $featureKey);

        if (! $record->exists) {
            $record->count = 0;
        }

        if ($record->count >= $limit) {
            return ['allowed' => false, 'used' => $record->count, 'remaining' => 0, 'limit' => $limit];
        }

        $record->count += 1;
        // Model::save() (appelé par update()) gère aussi bien l'INSERT (si
        // $record vient de firstOrNew sans exister encore) que l'UPDATE.
        $this->anonymousUsageRepository->update($record, ['count' => $record->count]);

        return ['allowed' => true, 'used' => $record->count, 'remaining' => $limit - $record->count, 'limit' => $limit];
    }

    /**
     * Statut en lecture seule — utilisé pour afficher le nombre d'usages
     * gratuits restants. Pas de validation côté source sur deviceId/
     * featureKey : préservé tel quel, ne pas introduire de FormRequest ici.
     *
     * @return array{allowed: bool, used: int, remaining: int, limit: int}
     */
    public function status(?string $deviceId, ?string $featureKey): array
    {
        $limit = self::FREE_LIMITS[$featureKey] ?? 3;

        $record = $this->anonymousUsageRepository->findByDeviceAndFeature((string) $deviceId, (string) $featureKey);
        $used = $record->count ?? 0;

        return ['allowed' => $used < $limit, 'used' => $used, 'remaining' => max(0, $limit - $used), 'limit' => $limit];
    }
}
