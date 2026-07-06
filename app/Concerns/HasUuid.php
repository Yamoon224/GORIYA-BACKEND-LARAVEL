<?php

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * PK uuid générée côté application (pas de dépendance à une extension Postgres
 * comme pgcrypto/uuid-ossp), équivalent à @PrimaryGeneratedColumn("uuid") de TypeORM.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function initializeHasUuid(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;
    }
}
