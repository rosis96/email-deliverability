<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Sanctum::actingAs(User::where('email', 'viewer@revcadence.local')->first());
    }

    public function test_overview_reports_verified_audit_totals(): void
    {
        $res = $this->getJson('/api/dashboard/overview')->assertOk();

        $res->assertJsonPath('totals.verified_accounts', 1564);
        $res->assertJsonPath('totals.connected_accounts', 1433);
        $res->assertJsonPath('totals.accounts_in_error', 131);
        $res->assertJsonPath('totals.total_batches', 11);
    }

    public function test_expired_workspace_is_not_reported_as_zero(): void
    {
        $res = $this->getJson('/api/workspaces')->assertOk();

        $free = collect($res->json('data'))->firstWhere('code', 'FREE');
        $this->assertNotNull($free);
        $this->assertNull($free['total_accounts']);          // never zero
        $this->assertStringContainsString('expired plan blocks access', $free['status_display']);
    }

    public function test_workspace_placement_is_not_copied_to_batches(): void
    {
        // Unicorn has workspace-level placement tests, but its batches must show "not tested".
        $batch = Batch::where('batch_id', 'UNI-SMTP-01')->first();
        $res = $this->getJson("/api/batches/{$batch->id}")->assertOk();

        $this->assertSame('not_tested', $res->json('data.header.inbox_placement'));
    }

    public function test_seeded_batches_have_unknown_dates_and_no_replacement_due(): void
    {
        $batch = Batch::where('batch_id', 'REV-SMTP-01')->first();
        $res = $this->getJson("/api/batches/{$batch->id}")->assertOk();

        $this->assertSame('dates_needed', $res->json('data.header.warmup_status'));
        $this->assertNull($res->json('data.header.replacement_due'));
        $this->assertSame('setup', $res->json('data.header.lifecycle_phase'));
    }

    public function test_revcadence_count_discrepancy_logged_as_unresolved(): void
    {
        $this->assertDatabaseHas('batch_problems', [
            'problem_code' => 'PRB-0001',
            'status' => 'open',
            'rule_key' => 'reconciliation',
        ]);
    }
}
