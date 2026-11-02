<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationConnection extends Model
{
    use HasUuids;

    protected $guarded = [];

    // Credentials are encrypted at rest and never serialised to API responses.
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'credentials' => 'encrypted',
            'last_success_at' => 'datetime',
        ];
    }

    public function apiKey(): ?string
    {
        // DB-stored encrypted key takes precedence; env is a fallback default.
        return $this->credentials ?: null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(IntegrationSyncLog::class);
    }
}
