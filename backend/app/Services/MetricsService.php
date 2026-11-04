<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\DailyActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Daily sending activity metrics (PRD §9). Every rate is derived from stored
 * source counts; denominators of zero/null yield null (never 0 or NaN) so the
 * UI can honestly show "—".
 */
class MetricsService
{
    private function rate(?int $numerator, ?int $denominator): ?float
    {
        if ($denominator === null || $denominator <= 0 || $numerator === null) {
            return null;
        }

        return round($numerator / $denominator * 100, 2);
    }

    private function capacity(?int $activeAccounts, ?int $limitPerAccount): ?int
    {
        if ($activeAccounts === null || $limitPerAccount === null) {
            return null;
        }

        return $activeAccounts * $limitPerAccount;
    }

    /**
     * Capacity for one day's record. Prefer the exact stored capacity (sum of
     * each sending mailbox's own limit, written by the Instantly sync); fall
     * back to active_accounts x per-account limit for manual/CSV rows.
     */
    private function rowCapacity(object $r): ?int
    {
        if (isset($r->daily_capacity) && $r->daily_capacity !== null) {
            return (int) $r->daily_capacity;
        }

        return $this->capacity($r->active_accounts ?? null, $r->daily_limit_per_account ?? null);
    }

    /** Derived metrics for a single day's record. */
    public function deriveDaily(DailyActivity $a): array
    {
        $capacity = $this->rowCapacity($a);

        return [
            'capacity' => $capacity,
            'utilisation' => $this->rate($a->sent, $capacity),
            'average_sent_per_account' => ($a->active_accounts && $a->active_accounts > 0)
                ? round($a->sent / $a->active_accounts, 2)
                : null,
            'reply_rate' => $this->rate($a->replies, $a->sent),
            'positive_reply_rate' => $this->rate($a->positive_replies, $a->sent),
            'bounce_rate' => $this->rate($a->bounces, $a->sent),
            'hard_bounce_rate' => $this->rate($a->hard_bounces, $a->sent),
        ];
    }

    /**
     * Aggregate a collection of DailyActivity rows into summed totals + derived
     * rates. Pure: works on any iterable, so it is unit-testable without a DB.
     */
    public function aggregate(iterable $rows): array
    {
        $rows = $rows instanceof Collection ? $rows : collect($rows);

        $sum = fn (string $field) => (int) $rows->sum(fn ($r) => (int) ($r->{$field} ?? 0));

        $sent = $sum('sent');
        $replies = $sum('replies');
        $positive = $sum('positive_replies');
        $bounces = $sum('bounces');
        $hardBounces = $sum('hard_bounces');

        // Capacity sums only days where both active_accounts and limit are known.
        $capacity = (int) $rows->sum(fn ($r) => $this->rowCapacity($r) ?? 0);

        return [
            'days_with_data' => $rows->count(),
            'sent' => $sent,
            'replies' => $replies,
            'positive_replies' => $positive,
            'bounces' => $bounces,
            'hard_bounces' => $hardBounces,
            'capacity' => $capacity > 0 ? $capacity : null,
            'utilisation' => $this->rate($sent, $capacity > 0 ? $capacity : null),
            'reply_rate' => $this->rate($replies, $sent),
            'positive_reply_rate' => $this->rate($positive, $sent),
            'bounce_rate' => $this->rate($bounces, $sent),
            'hard_bounce_rate' => $this->rate($hardBounces, $sent),
        ];
    }

    /** Rolling window ending yesterday (inclusive) spanning $days days. */
    public function rolling(Batch $batch, int $days, ?CarbonInterface $today = null): array
    {
        $today = $today ? CarbonImmutable::parse($today)->startOfDay() : CarbonImmutable::now()->startOfDay();
        $end = $today->subDay();                 // through yesterday
        $start = $end->subDays($days - 1);

        $rows = $batch->dailyActivity()
            ->whereBetween('activity_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'window_days' => $days,
        ] + $this->aggregate($rows);
    }

    public function yesterday(Batch $batch, ?CarbonInterface $today = null): ?array
    {
        $today = $today ? CarbonImmutable::parse($today)->startOfDay() : CarbonImmutable::now()->startOfDay();
        $date = $today->subDay()->toDateString();

        $row = $batch->dailyActivity()->where('activity_date', $date)->first();

        if (! $row) {
            return null;
        }

        return ['date' => $date, 'sent' => $row->sent] + $this->deriveDaily($row) + [
            'replies' => $row->replies,
            'positive_replies' => $row->positive_replies,
            'bounces' => $row->bounces,
        ];
    }

    /** Dashboard activity block: yesterday + 7-day + 30-day. */
    public function summaryForBatch(Batch $batch, ?CarbonInterface $today = null): array
    {
        return [
            'yesterday' => $this->yesterday($batch, $today),
            'last_7_days' => $this->rolling($batch, 7, $today),
            'last_30_days' => $this->rolling($batch, 30, $today),
        ];
    }
}
