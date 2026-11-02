<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailAccount extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    // Defence in depth: these must never be persisted or serialised.
    protected $hidden = ['password', 'smtp_password', 'imap_password', 'access_token'];

    protected function casts(): array
    {
        return [
            'warmup_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AccountException::class);
    }
}
