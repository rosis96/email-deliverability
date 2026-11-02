<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RotationPlan extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'overlap_start' => 'date',
            'overlap_end' => 'date',
            'retirement_date' => 'date',
        ];
    }

    public function currentBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'current_batch_id');
    }

    public function replacementBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'replacement_batch_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
