<?php

namespace App\Repositories\Contracts;

use App\Models\AnonymousUsage;

interface AnonymousUsageRepositoryInterface extends RepositoryInterface
{
    public function findOrNew(string $deviceId, string $featureKey): AnonymousUsage;

    public function findByDeviceAndFeature(string $deviceId, string $featureKey): ?AnonymousUsage;
}
