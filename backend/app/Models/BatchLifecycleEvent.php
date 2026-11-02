<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchLifecycleEvent extends Model
{
    use HasUuids;

    protected $table = 'batch_lifecycle';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
