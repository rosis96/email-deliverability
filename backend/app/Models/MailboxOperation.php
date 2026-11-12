<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxOperation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['emails'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'emails' => 'array',
            'log' => 'array',
            'total' => 'integer',
            'done' => 'integer',
            'failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function note(string $line): void
    {
        $log = $this->log ?? [];
        $log[] = now()->format('H:i:s').' '.$line;
        $this->log = array_slice($log, -200);
    }
}
