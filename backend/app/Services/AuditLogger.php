<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(string $action, ?Model $entity = null, array $changes = [], ?User $user = null): AuditLog
    {
        $user = $user ?? Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
