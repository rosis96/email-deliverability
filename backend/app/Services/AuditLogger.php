<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * Record an audit entry.
     *
     * When there is no Eloquent model to anchor the entry to (e.g. a write to an
     * external system like an Instantly mailbox), pass $entityType/$entityId so
     * the log still names WHAT was acted on — otherwise the Entity column renders
     * an empty "—".
     */
    public function log(
        string $action,
        ?Model $entity = null,
        array $changes = [],
        ?User $user = null,
        ?string $entityType = null,
        ?string $entityId = null,
    ): AuditLog {
        $user = $user ?? Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : $entityType,
            'entity_id' => $entity?->getKey() ?? $entityId,
            'changes' => $changes ?: null,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
