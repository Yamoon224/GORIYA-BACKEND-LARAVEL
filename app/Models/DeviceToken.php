<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasUuid;
use App\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeton push (FCM) d'un appareil de l'utilisateur — voir
 * PushNotificationServiceInterface et NotificationService, qui l'utilisent
 * pour router les notifications in-app existantes vers le mobile.
 */
class DeviceToken extends Model
{
    use Auditable, HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token',
        'platform',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
