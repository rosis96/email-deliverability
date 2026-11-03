<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $appends = ['entity_label'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human-readable name of the thing this entry acted on, for the audit table.
     * Prefers an email/identifier in entity_id or changes over the raw model
     * class, so external-system writes (e.g. Instantly mailboxes) read clearly
     * instead of rendering an empty "—".
     */
    protected function entityLabel(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            $id = $this->entity_id;
            if (is_string($id) && str_contains($id, '@')) {
                return $id;
            }
            $changes = $this->changes;
            if (is_array($changes) && ! empty($changes['email'])) {
                return (string) $changes['email'];
            }

            return $this->entity_type ?: ($id !== null ? (string) $id : null);
        });
    }
}
