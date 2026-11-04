<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyActivity extends Model
{
    use HasUuids;

    protected $table = 'daily_activity';

    protected $guarded = [];

    /**
     * Store activity_date as a pure Y-m-d string, independent of the DB driver.
     * A `date` cast persists via the connection's datetime format (…" 00:00:00"
     * on SQLite), which breaks exact-date lookups and the (batch_id, date)
     * uniqueness upsert. Normalising on write keeps it portable and keyed cleanly.
     */
    protected function activityDate(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->toDateString(),
        );
    }

    protected function casts(): array
    {
        return [
            'active_accounts' => 'integer',
            'connected_accounts' => 'integer',
            'sent' => 'integer',
            'daily_limit_per_account' => 'integer',
            'daily_capacity' => 'integer',
            'replies' => 'integer',
            'positive_replies' => 'integer',
            'bounces' => 'integer',
            'hard_bounces' => 'integer',
            'soft_bounces' => 'integer',
            'rejections' => 'integer',
            'warmup_emails' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
