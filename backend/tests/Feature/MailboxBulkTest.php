<?php

namespace Tests\Feature;

use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Models\MailboxOperation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Integrations\MailboxBulkService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailboxBulkTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->ws = Workspace::where('code', 'BS')->first();
        IntegrationConnection::updateOrCreate(
            ['provider' => 'instantly', 'workspace_id' => $this->ws->id],
            ['credentials' => 'test-key-1234567890', 'status' => 'connected'],
        );
        $svc = app(MailboxBulkService::class);
        $svc->throttleMicros = 0;
        $this->app->instance(MailboxBulkService::class, $svc);
        Sanctum::actingAs(User::where('email', 'operator@revcadence.local')->first());

        foreach ([['a@bs.com', 'active', 'T1'], ['b@bs.com', 'connection_error', 'T1'], ['c@bs.com', 'paused', 'T2']] as [$e, $st, $tag]) {
            ImportedRecord::create([
                'source' => 'instantly', 'entity_type' => 'account', 'external_record_id' => $e,
                'workspace_id' => $this->ws->id, 'batch_mapping_confidence' => 'unmapped', 'imported_at' => now(),
                'payload' => ['email' => $e, 'status' => $st, 'daily_limit' => 5, 'warmup_status' => 1,
                    'stat_warmup_score' => 100, 'tags' => [['id' => 'id-'.$tag, 'label' => 'Batch '.$tag]]],
            ]);
        }
    }

    public function test_summary_groups_by_workspace_and_tag(): void
    {
        $res = $this->getJson('/api/mailboxes/summary')->assertOk();
        $bs = collect($res->json('data'))->firstWhere('code', 'BS');
        $this->assertSame(3, $bs['totals']['total']);
        $t1 = collect($bs['tags'])->firstWhere('label', 'Batch T1');
        $this->assertSame([2, 1, 1, 'bad'], [$t1['total'], $t1['active'], $t1['error'], $t1['health']]);
        $this->assertSame(5, $t1['daily_capacity']);
    }

    public function test_bulk_daily_limit_for_a_whole_tag(): void
    {
        Http::fake([
            'api.instantly.ai/api/v2/accounts?*' => Http::response(['items' => [['email' => 'a@bs.com'], ['email' => 'b@bs.com']], 'next_starting_after' => null]),
            'api.instantly.ai/api/v2/accounts/*' => Http::response(['email' => 'x', 'daily_limit' => 12]),
        ]);

        $dry = $this->postJson("/api/instantly/workspaces/{$this->ws->id}/bulk", [
            'action' => 'daily_limit', 'value' => 12, 'scope' => 'filter', 'filter' => ['tag_ids' => 'id-T1'], 'dry_run' => true,
        ])->assertOk();
        $this->assertSame(2, $dry->json('data.count'));

        $res = $this->postJson("/api/instantly/workspaces/{$this->ws->id}/bulk", [
            'action' => 'daily_limit', 'value' => 12, 'scope' => 'filter', 'filter' => ['tag_ids' => 'id-T1'],
        ])->assertStatus(202);

        $op = MailboxOperation::find($res->json('data.id'));
        $this->assertSame('completed', $op->status, implode("\n", $op->log ?? []));
        $this->assertSame(2, $op->done);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['daily_limit'] === 12);
        $this->assertSame(12, ImportedRecord::where('external_record_id', 'a@bs.com')->first()->payload['daily_limit']);
    }

    public function test_bulk_warmup_and_validation(): void
    {
        Http::fake(['api.instantly.ai/*' => Http::response(['id' => 'job'])]);

        $this->postJson("/api/instantly/workspaces/{$this->ws->id}/bulk", [
            'action' => 'daily_limit', 'value' => '', 'scope' => 'emails', 'emails' => ['a@bs.com'],
        ])->assertStatus(422);

        $res = $this->postJson("/api/instantly/workspaces/{$this->ws->id}/bulk", [
            'action' => 'warmup_off', 'scope' => 'emails', 'emails' => ['a@bs.com', 'c@bs.com'],
        ])->assertStatus(202);
        $this->assertSame('completed', MailboxOperation::find($res->json('data.id'))->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/accounts/warmup/disable') && count($r['emails']) === 2);
    }

    public function test_viewer_cannot_bulk_edit(): void
    {
        Sanctum::actingAs(User::where('email', 'viewer@revcadence.local')->first());
        $this->postJson("/api/instantly/workspaces/{$this->ws->id}/bulk", [
            'action' => 'pause', 'scope' => 'emails', 'emails' => ['a@bs.com'],
        ])->assertForbidden();
    }
}
