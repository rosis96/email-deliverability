<?php

namespace Tests\Unit;

use App\Models\Batch;
use App\Services\LifecycleService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LifecycleServiceTest extends TestCase
{
    private LifecycleService $service;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LifecycleService();
        $this->today = CarbonImmutable::parse('2026-11-02');
    }

    private function batch(array $attrs): Batch
    {
        return new Batch($attrs);
    }

    public function test_missing_warmup_start_returns_dates_needed(): void
    {
        $warmup = $this->service->warmup($this->batch(['warmup_days' => 21]), $this->today);

        $this->assertSame('dates_needed', $warmup['status']);
        $this->assertNull($warmup['start']);
        $this->assertNull($warmup['elapsed']);
        $this->assertNull($warmup['remaining']);
    }

    public function test_warming_up_computes_elapsed_and_remaining(): void
    {
        $warmup = $this->service->warmup(
            $this->batch(['warmup_start' => '2026-10-25', 'warmup_days' => 21]),
            $this->today,
        );

        $this->assertSame('warming_up', $warmup['status']);
        $this->assertSame('2026-11-15', $warmup['end']);
        $this->assertSame(8, $warmup['elapsed']);
        $this->assertSame(13, $warmup['remaining']);
    }

    public function test_warmup_complete_after_end_date(): void
    {
        $warmup = $this->service->warmup(
            $this->batch(['warmup_start' => '2026-10-01', 'warmup_days' => 21]),
            $this->today,
        );

        $this->assertSame('complete', $warmup['status']);
        $this->assertSame('2026-10-22', $warmup['end']);
        $this->assertSame(32, $warmup['elapsed']);
        $this->assertLessThan(0, $warmup['remaining']);
    }

    public function test_missing_active_start_produces_no_replacement_due(): void
    {
        $active = $this->service->active($this->batch(['lifecycle_days' => 90]), $this->today);

        $this->assertNull($active['active_start']);
        $this->assertNull($active['active_age']);
        $this->assertNull($active['replacement_due']);
        $this->assertNull($active['days_until_replacement']);
        $this->assertNull($this->service->replacementDue($this->batch(['lifecycle_days' => 90])));
    }

    public function test_active_age_and_replacement_due(): void
    {
        $active = $this->service->active(
            $this->batch(['active_start' => '2026-08-04', 'lifecycle_days' => 90]),
            $this->today,
        );

        $this->assertSame(90, $active['active_age']);
        $this->assertSame('2026-11-02', $active['replacement_due']);
        $this->assertSame(0, $active['days_until_replacement']);
    }

    public function test_is_replacement_due_within_window(): void
    {
        $dueSoon = $this->batch(['active_start' => $this->today->subDays(80)->toDateString(), 'lifecycle_days' => 90]);
        $dueLater = $this->batch(['active_start' => $this->today->subDays(40)->toDateString(), 'lifecycle_days' => 90]);

        $this->assertTrue($this->service->isReplacementDueWithin($dueSoon, 30, $this->today));   // due in ~10 days
        $this->assertFalse($this->service->isReplacementDueWithin($dueLater, 30, $this->today));  // due in ~50 days
        $this->assertFalse($this->service->isReplacementDueWithin($this->batch([]), 30, $this->today));
    }

    public function test_warmup_complete_does_not_imply_readiness(): void
    {
        // 21 days elapsed, but readiness must remain a separate, explicit flag.
        $batch = $this->batch(['warmup_start' => '2026-10-01', 'warmup_days' => 21]);
        $snapshot = $this->service->snapshot($batch, $this->today);

        $this->assertSame('complete', $snapshot['warmup']['status']);
        $this->assertFalse($snapshot['readiness_checks_passed']);
    }
}
