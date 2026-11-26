<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ImportedRecord;
use App\Models\InfrastructurePlan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InfrastructurePlanTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->ws = Workspace::where('code', 'BS')->first();
        Sanctum::actingAs(User::where('email', 'operator@revcadence.local')->first());
    }

    private function inputs(array $over = []): array
    {
        return array_merge([
            'providers' => [
                ['id' => 'g', 'name' => 'Google - Zapmail', 'kind' => 'google', 'cost_per_account' => 3.5,
                    'daily_limit' => 15, 'mix_pct' => 60, 'active' => 40, 'backup' => 5, 'disconnected' => 2],
                ['id' => 's', 'name' => 'SMTP - Maildoso', 'kind' => 'smtp', 'cost_per_account' => 1.5,
                    'daily_limit' => 15, 'mix_pct' => 40, 'active' => 20, 'backup' => 0, 'disconnected' => 0],
            ],
            'sending_days_per_month' => 22,
            'meeting_target' => 20,
            'reply_to_meeting_pct' => 40,
            'emails_per_prospect' => 3,
            'backup_pct' => 20,
            'selected_reply_rate' => 0.5,
            'reply_rates' => [0.1, 0.2, 0.3, 0.5, 0.7, 0.8, 1, 2, 3],
            'notes' => null,
        ], $over);
    }

    public function test_index_lists_workspaces_with_live_snapshot(): void
    {
        foreach ([['a@bs.com', 'active', 'google', 10], ['b@bs.com', 'connection_error', 'custom_smtp', 10]] as [$e, $st, $prov, $dl]) {
            ImportedRecord::create([
                'source' => 'instantly', 'entity_type' => 'account', 'external_record_id' => $e,
                'workspace_id' => $this->ws->id, 'batch_mapping_confidence' => 'unmapped', 'imported_at' => now(),
                'payload' => ['email' => $e, 'status' => $st, 'provider' => $prov, 'daily_limit' => $dl],
            ]);
        }

        $res = $this->getJson('/api/infrastructure/plans')->assertOk();
        $bs = collect($res->json('data'))->firstWhere('code', 'BS');
        $this->assertNull($bs['inputs']);
        $this->assertSame([2, 1, 1, 10], [$bs['live']['total'], $bs['live']['active'], $bs['live']['error'], $bs['live']['daily_capacity']]);
        $this->assertSame(['google' => 1, 'custom_smtp' => 1], $bs['live']['by_provider']);
    }

    public function test_operator_saves_plan_and_it_is_audited(): void
    {
        $this->putJson("/api/infrastructure/plans/{$this->ws->id}", ['inputs' => $this->inputs()])->assertOk();
        $this->assertSame(20, InfrastructurePlan::where('workspace_id', $this->ws->id)->first()->inputs['meeting_target']);
        $this->assertTrue(AuditLog::where('action', 'infrastructure.plan.updated')->exists());

        // Saving again updates the same row.
        $this->putJson("/api/infrastructure/plans/{$this->ws->id}", ['inputs' => $this->inputs(['meeting_target' => 30])])->assertOk();
        $this->assertSame(1, InfrastructurePlan::count());
        $bs = collect($this->getJson('/api/infrastructure/plans')->json('data'))->firstWhere('code', 'BS');
        $this->assertSame(30, $bs['inputs']['meeting_target']);
    }

    public function test_mix_must_total_100_and_rates_validated(): void
    {
        $bad = $this->inputs();
        $bad['providers'][0]['mix_pct'] = 50;
        $this->putJson("/api/infrastructure/plans/{$this->ws->id}", ['inputs' => $bad])->assertStatus(422);

        $this->putJson("/api/infrastructure/plans/{$this->ws->id}", ['inputs' => $this->inputs(['reply_to_meeting_pct' => 0])])
            ->assertStatus(422);
    }

    public function test_viewer_cannot_save(): void
    {
        Sanctum::actingAs(User::where('email', 'viewer@revcadence.local')->first());
        $this->getJson('/api/infrastructure/plans')->assertOk();
        $this->putJson("/api/infrastructure/plans/{$this->ws->id}", ['inputs' => $this->inputs()])->assertForbidden();
    }
}
