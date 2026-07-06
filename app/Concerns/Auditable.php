<?php

namespace App\Concerns;

use App\Services\AuditLogService;

/**
 * Journalise automatiquement created/updated/deleted dans audit_logs.
 * Un modèle peut définir `protected array $auditExcludes = [...]` pour
 * exclure des attributs supplémentaires (en plus de password/remember_token/
 * created_at/updated_at, déjà exclus par défaut).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            app(AuditLogService::class)->log('created', $model, [], $model->auditableAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->auditableChanges();

            if ($changes === []) {
                return;
            }

            $old = collect($model->getOriginal())->only(array_keys($changes))->toArray();

            app(AuditLogService::class)->log('updated', $model, $old, $changes);
        });

        static::deleted(function ($model) {
            app(AuditLogService::class)->log('deleted', $model, $model->auditableAttributes(), []);
        });
    }

    protected function auditExcludedAttributes(): array
    {
        return array_merge(['password', 'remember_token', 'created_at', 'updated_at'], $this->auditExcludes ?? []);
    }

    protected function auditableAttributes(): array
    {
        return collect($this->getAttributes())->except($this->auditExcludedAttributes())->toArray();
    }

    protected function auditableChanges(): array
    {
        return collect($this->getChanges())->except($this->auditExcludedAttributes())->toArray();
    }
}
