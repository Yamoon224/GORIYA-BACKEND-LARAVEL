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
            static::writeAudit('created', $model, [], $model->auditableAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->auditableChanges();

            if ($changes === []) {
                return;
            }

            $old = collect($model->getOriginal())->only(array_keys($changes))->toArray();

            static::writeAudit('updated', $model, $old, $changes);
        });

        static::deleted(function ($model) {
            // Ne pas conserver le contenu du modèle supprimé (DCP) dans old_values :
            // seule la preuve que la suppression a eu lieu (type, id, auteur, date) est journalisée.
            static::writeAudit('deleted', $model, [], []);
        });
    }

    /**
     * Le journal d'audit ne doit jamais faire échouer l'opération métier qu'il
     * trace : un insert audit_logs en erreur a déjà interrompu le webhook
     * Paiement Pro après le passage en SUCCESS, donc avant l'activation.
     */
    protected static function writeAudit(string $action, $model, array $old, array $new): void
    {
        try {
            app(AuditLogService::class)->log($action, $model, $old, $new);
        } catch (\Throwable $e) {
            report($e);
        }
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
