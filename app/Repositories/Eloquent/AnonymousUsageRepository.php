<?php

namespace App\Repositories\Eloquent;

use App\Models\AnonymousUsage;
use App\Repositories\Contracts\AnonymousUsageRepositoryInterface;

class AnonymousUsageRepository extends BaseRepository implements AnonymousUsageRepositoryInterface
{
    protected function model(): string
    {
        return AnonymousUsage::class;
    }

    public function findOrNew(string $deviceId, string $featureKey): AnonymousUsage
    {
        return AnonymousUsage::firstOrNew([
            'device_id' => $deviceId,
            'feature_key' => $featureKey,
        ]);
    }

    public function findByDeviceAndFeature(string $deviceId, string $featureKey): ?AnonymousUsage
    {
        return AnonymousUsage::where('device_id', $deviceId)
            ->where('feature_key', $featureKey)
            ->first();
    }
}
