<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * Point d'entrée unique pour écrire (log) et lire (paginate/find) le
 * journal d'audit. Le trait App\Concerns\Auditable l'appelle automatiquement
 * pour created/updated/deleted ; les flux hors-Eloquent (login/logout/...)
 * l'appellent explicitement depuis les Services concernés.
 */
class AuditLogService
{
    public function log(string $action, ?Model $auditable = null, array $oldValues = [], array $newValues = [], ?User $actor = null): AuditLog
    {
        $actor ??= auth('api')->user();
        $request = request();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'user_email' => $actor?->email,
            'user_role' => $actor?->role instanceof \BackedEnum ? $actor->role->value : $actor?->role,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'url' => $request?->fullUrl(),
            'method' => $request?->method(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $query = AuditLog::query()->with('user')->orderByDesc('created_at');

        if ($userId = $filters['userId'] ?? null) {
            $query->where('user_id', $userId);
        }
        if ($action = $filters['action'] ?? null) {
            $query->where('action', $action);
        }
        if ($type = $filters['auditableType'] ?? null) {
            $query->where('auditable_type', $type);
        }
        if ($auditableId = $filters['auditableId'] ?? null) {
            $query->where('auditable_id', $auditableId);
        }
        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'like', "%{$search}%")
                    ->orWhere('user_email', 'like', "%{$search}%");
            });
        }
        if ($from = $filters['dateFrom'] ?? null) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $filters['dateTo'] ?? null) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function find(string $id): ?AuditLog
    {
        return AuditLog::with('user')->find($id);
    }

    /**
     * @return array<int, string>
     */
    public function distinctActions(): array
    {
        return AuditLog::query()->distinct()->orderBy('action')->pluck('action')->all();
    }
}
