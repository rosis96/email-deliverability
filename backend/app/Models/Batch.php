<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Batch extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'creation_date' => 'date',
            'warmup_start' => 'date',
            'active_start' => 'date',
            'warmup_days' => 'integer',
            'lifecycle_days' => 'integer',
            'planned_account_count' => 'integer',
            'actual_account_count' => 'integer',
            'active_account_count' => 'integer',
            'error_account_count' => 'integer',
            'active_daily_limit' => 'integer',
            'readiness_checks_passed' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function replacementBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'replacement_batch_id');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    public function dailyActivity(): HasMany
    {
        return $this->hasMany(DailyActivity::class);
    }

    public function rampSchedules(): HasMany
    {
        return $this->hasMany(RampSchedule::class);
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(BatchLifecycleEvent::class);
    }

    public function placementTests(): HasMany
    {
        return $this->hasMany(PlacementTest::class);
    }

    public function problems(): HasMany
    {
        return $this->hasMany(BatchProblem::class);
    }

    public function accountExceptions(): HasMany
    {
        return $this->hasMany(AccountException::class);
    }

    /** Error percentage of accounts in this batch (0 when no accounts). */
    public function errorPercentage(): float
    {
        if ($this->actual_account_count <= 0) {
            return 0.0;
        }

        return round($this->error_account_count / $this->actual_account_count * 100, 2);
    }
}
