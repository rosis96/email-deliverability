<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchOperation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'log' => 'array',
            'total_steps' => 'integer',
            'done_steps' => 'integer',
            'failed_steps' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Append a line to the run log (kept to the last 200 lines). */
    public function note(string $line): void
    {
        $log = $this->log ?? [];
        $log[] = now()->format('H:i:s').' '.$line;
        $this->log = array_slice($log, -200);
    }
}
