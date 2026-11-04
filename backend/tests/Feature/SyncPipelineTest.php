<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchProblem;
use App\Models\DailyActivity;
use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Sync now" must run the whole pipeline: sync -> map -> daily activity ->
 * detection, so the dashboard reflects live Instantly data immediately.
 */
class SyncPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function fakeInstantly(array $accounts): void
    {
        Http::fake([
            'api.instantly.ai/api/v2/workspaces/current' => Http::response(['id' => 'ws-fw', 'name' => 'Future works']),
            'api.instantly.ai/api/v2/accounts/analytics/daily*' => Http::response(array_map(
                fn ($a) => ['email_account' => $a['email'], 'date' => now()->subDay()->toDateString(), 'sent' => 10, 'bounced' => 0, 'replies' => 1],
                $accounts,
            )),
            'api.instantly.ai/api/v2/accounts*' => Http::response(['items' => $accounts, 'next_starting_after' => null]),
            'api.instantly.ai/api/v2/campaigns*' => Http::response(['items' => [], 'next_starting_after' => null]),
        ]);
    }

    private function connection(): IntegrationConnection
    {
        $ws = Workspace::where('code', 'FW')->first();

        return IntegrationConnection::updateOrCreate(
            ['provider' => 'instantly', 'workspace_id' => $ws->id],
            ['credentials' => 'test-key-1234567890', 'status' => 'connected'],
        );
    }

    public function test_sync_now_maps_accounts_pulls_activity_and_records_problems(): void
    {
        $accounts = [];
        for ($i = 1; $i <= 20; $i++) {
            // 15 Google accounts in connection_error (status -1), 5 healthy SMTP.
            $accounts[] = $i <= 15
                ? ['email' => "g{$i}@fw-google.com", 'status' => -1, 'provider_code' => 2, 'daily_limit' => 20]
                : ['email' => "s{$i}@fw-smtp.com", 'status' => 1, 'provider_code' => 1, 'daily_limit' => 20];
        }
        $this->fakeInstantly($accounts);
        $conn = $this->connection();

        Sanctum::actingAs(User::where('email', 'admin@revcadence.local')->first());
        $res = $this->postJson("/api/integrations/{$conn->id}/sync")->assertOk();
        $this->assertStringContainsString('Mapped 20 mailbox(es)', $res->json('message'));
        $this->assertSame(0, $res->json('connection.unmapped_accounts'));

        $dfy = Batch::where('batch_id', 'FW-DFY-01')->first();
        $this->assertSame([15, 0, 15], [$dfy->actual_account_count, $dfy->active_account_count, $dfy->error_account_count]);

        $this->assertTrue(DailyActivity::where('batch_id', $dfy->id)->exists());
        $this->assertTrue(BatchProblem::where('batch_id', $dfy->id)->where('rule_key', 'error_pct')->where('status', 'open')->exists());

        // Re-sync must keep the mapping (it used to reset every record to "unmapped").
        $this->postJson("/api/integrations/{$conn->id}/sync")->assertOk();
        $this->assertSame(0, ImportedRecord::where('workspace_id', $conn->workspace_id)->whereNull('batch_id')->count());
    }

    public function test_failed_connect_marks_connection_disconnected_with_reason(): void
    {
        Http::fake(['api.instantly.ai/*' => Http::response(['error' => 'unauthorized'], 401)]);
        $ws = Workspace::where('code', 'FW')->first();

        Sanctum::actingAs(User::where('email', 'admin@revcadence.local')->first());
        $this->postJson('/api/integrations/connect', [
            'provider' => 'instantly', 'workspace_id' => $ws->id, 'api_key' => 'bad-key-1234567890',
        ])->assertStatus(422)
            ->assertJsonPath('connection.status', 'disconnected');

        $this->assertNotNull(IntegrationConnection::where('workspace_id', $ws->id)->first()->last_error);
    }
}
