<?php

namespace Tests\Unit;

use App\Models\DailyActivity;
use App\Services\MetricsService;
use PHPUnit\Framework\TestCase;

class MetricsServiceTest extends TestCase
{
    private MetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MetricsService();
    }

    private function day(array $attrs): DailyActivity
    {
        return new DailyActivity($attrs);
    }

    public function test_derives_capacity_utilisation_and_rates(): void
    {
        $derived = $this->service->deriveDaily($this->day([
            'active_accounts' => 10,
            'daily_limit_per_account' => 20,
            'sent' => 100,
            'replies' => 10,
            'positive_replies' => 4,
            'bounces' => 5,
            'hard_bounces' => 2,
        ]));

        $this->assertSame(200, $derived['capacity']);
        $this->assertSame(50.0, $derived['utilisation']);
        $this->assertSame(10.0, $derived['average_sent_per_account']);
        $this->assertSame(10.0, $derived['reply_rate']);
        $this->assertSame(4.0, $derived['positive_reply_rate']);
        $this->assertSame(5.0, $derived['bounce_rate']);
        $this->assertSame(2.0, $derived['hard_bounce_rate']);
    }

    public function test_avoids_division_by_zero(): void
    {
        $derived = $this->service->deriveDaily($this->day([
            'active_accounts' => 0,
            'daily_limit_per_account' => 20,
            'sent' => 0,
            'replies' => 0,
            'bounces' => 0,
        ]));

        $this->assertNull($derived['utilisation']);          // capacity 0
        $this->assertNull($derived['reply_rate']);           // sent 0
        $this->assertNull($derived['bounce_rate']);
        $this->assertNull($derived['average_sent_per_account']);
    }

    public function test_null_limit_yields_null_capacity(): void
    {
        $derived = $this->service->deriveDaily($this->day([
            'active_accounts' => 10,
            'daily_limit_per_account' => null,
            'sent' => 50,
        ]));

        $this->assertNull($derived['capacity']);
        $this->assertNull($derived['utilisation']);
    }

    public function test_aggregate_sums_and_rates(): void
    {
        $rows = [
            $this->day(['active_accounts' => 10, 'daily_limit_per_account' => 20, 'sent' => 100, 'replies' => 5, 'bounces' => 2]),
            $this->day(['active_accounts' => 10, 'daily_limit_per_account' => 20, 'sent' => 100, 'replies' => 15, 'bounces' => 8]),
        ];

        $agg = $this->service->aggregate($rows);

        $this->assertSame(2, $agg['days_with_data']);
        $this->assertSame(200, $agg['sent']);
        $this->assertSame(20, $agg['replies']);
        $this->assertSame(10, $agg['bounces']);
        $this->assertSame(400, $agg['capacity']);
        $this->assertSame(50.0, $agg['utilisation']);   // 200/400
        $this->assertSame(10.0, $agg['reply_rate']);    // 20/200
        $this->assertSame(5.0, $agg['bounce_rate']);    // 10/200
    }

    public function test_empty_aggregate_is_null_safe(): void
    {
        $agg = $this->service->aggregate([]);

        $this->assertSame(0, $agg['days_with_data']);
        $this->assertSame(0, $agg['sent']);
        $this->assertNull($agg['capacity']);
        $this->assertNull($agg['utilisation']);
        $this->assertNull($agg['reply_rate']);
    }
}
