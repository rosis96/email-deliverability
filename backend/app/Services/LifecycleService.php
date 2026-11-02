<?php

namespace App\Services;

use App\Models\Batch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Lifecycle & date calculation engine.
 *
 * Hard rules enforced here (see PRD §8):
 *  - Never invent or backfill dates.
 *  - Missing warmup_start  => warmup status is "dates_needed".
 *  - Missing active_start  => NO active age and NO replacement due date.
 *  - 21 elapsed days never, by itself, marks a batch ready.
 *
 * All outputs are derived purely from stored source fields, so every value is
 * reproducible from the batch record alone.
 */
class LifecycleService
{
    public const PHASES = ['setup', 'warmup', 'ramp', 'active', 'resting', 'retired'];

    public const PHASE_LABELS = [
        'setup' => 'Setup',
        'warmup' => 'Warmup',
        'ramp' => 'Ramp',
        'active' => 'Active',
        'resting' => 'Resting / Rotation candidate',
        'retired' => 'Retired / Replaced',
    ];

    /** Signed whole-day difference (to - from). App timezone is UTC, so no DST drift. */
    private function daysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $a = $from->copy()->startOfDay()->getTimestamp();
        $b = $to->copy()->startOfDay()->getTimestamp();

        return (int) round(($b - $a) / 86400);
    }

    private function today(?CarbonInterface $today): CarbonImmutable
    {
        return $today
            ? CarbonImmutable::parse($today)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
    }

    /**
     * Warmup calculation.
     *
     * @return array{status:string,start:?string,end:?string,days:int,elapsed:?int,remaining:?int}
     */
    public function warmup(Batch $batch, ?CarbonInterface $today = null): array
    {
        $days = $batch->warmup_days ?? 21;

        if (empty($batch->warmup_start)) {
            return [
                'status' => 'dates_needed',
                'start' => null,
                'end' => null,
                'days' => $days,
                'elapsed' => null,
                'remaining' => null,
            ];
        }

        $today = $this->today($today);
        $start = CarbonImmutable::parse($batch->warmup_start)->startOfDay();
        $end = $start->addDays($days);

        $elapsed = $this->daysBetween($start, $today);
        $remaining = $this->daysBetween($today, $end);

        // "warming_up" while today is before warmup_end; "complete" on/after it.
        $status = $today->lt($end) ? 'warming_up' : 'complete';

        return [
            'status' => $status,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'elapsed' => $elapsed,
            'remaining' => $remaining,
        ];
    }

    /**
     * Active lifecycle calculation. Returns nulls when active_start is missing —
     * a replacement due date is NEVER produced without a verified active start.
     *
     * @return array{active_start:?string,active_age:?int,lifecycle_days:int,replacement_due:?string,days_until_replacement:?int}
     */
    public function active(Batch $batch, ?CarbonInterface $today = null): array
    {
        $lifecycleDays = $batch->lifecycle_days ?? 90;

        if (empty($batch->active_start)) {
            return [
                'active_start' => null,
                'active_age' => null,
                'lifecycle_days' => $lifecycleDays,
                'replacement_due' => null,
                'days_until_replacement' => null,
            ];
        }

        $today = $this->today($today);
        $start = CarbonImmutable::parse($batch->active_start)->startOfDay();
        $replacementDue = $start->addDays($lifecycleDays);

        return [
            'active_start' => $start->toDateString(),
            'active_age' => $this->daysBetween($start, $today),
            'lifecycle_days' => $lifecycleDays,
            'replacement_due' => $replacementDue->toDateString(),
            'days_until_replacement' => $this->daysBetween($today, $replacementDue),
        ];
    }

    public function replacementDue(Batch $batch): ?CarbonImmutable
    {
        if (empty($batch->active_start)) {
            return null;
        }

        return CarbonImmutable::parse($batch->active_start)
            ->startOfDay()
            ->addDays($batch->lifecycle_days ?? 90);
    }

    /** True only when a verified active_start yields a due date within $days from today. */
    public function isReplacementDueWithin(Batch $batch, int $days = 30, ?CarbonInterface $today = null): bool
    {
        $due = $this->replacementDue($batch);
        if ($due === null) {
            return false;
        }

        $diff = $this->daysBetween($this->today($today), $due);

        return $diff <= $days;
    }

    /** Combined lifecycle snapshot used by dashboards and the batch detail page. */
    public function snapshot(Batch $batch, ?CarbonInterface $today = null): array
    {
        return [
            'phase' => $batch->phase,
            'phase_label' => self::PHASE_LABELS[$batch->phase] ?? ucfirst((string) $batch->phase),
            'warmup' => $this->warmup($batch, $today),
            'active' => $this->active($batch, $today),
            'readiness_checks_passed' => (bool) $batch->readiness_checks_passed,
        ];
    }
}
