<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_batch_cannot_be_hard_deleted(): void
    {
        Sanctum::actingAs(User::where('email', 'operator@revcadence.local')->first());
        $batch = Batch::where('batch_id', 'NE-SMTP-01')->first();

        $this->deleteJson("/api/batches/{$batch->id}")->assertStatus(409);
        $this->assertDatabaseHas('batches', ['batch_id' => 'NE-SMTP-01']);
    }

    public function test_duplicate_daily_activity_updates_not_duplicates(): void
    {
        Sanctum::actingAs(User::where('email', 'operator@revcadence.local')->first());
        $batch = Batch::where('batch_id', 'NE-SMTP-01')->first();

        $payload = ['activity_date' => '2026-11-01', 'sent' => 100, 'active_accounts' => 10, 'daily_limit_per_account' => 20];
        $this->postJson("/api/batches/{$batch->id}/activity", $payload)->assertCreated();
        $this->postJson("/api/batches/{$batch->id}/activity", array_merge($payload, ['sent' => 150]))->assertCreated();

        $this->assertSame(1, $batch->dailyActivity()->where('activity_date', '2026-11-01')->count());
        $this->assertSame(150, $batch->dailyActivity()->where('activity_date', '2026-11-01')->first()->sent);
    }

    public function test_batch_id_must_be_unique_and_well_formed(): void
    {
        Sanctum::actingAs(User::where('email', 'operator@revcadence.local')->first());
        $ws = Workspace::where('code', 'NE')->first();

        // Duplicate
        $this->postJson('/api/batches', [
            'batch_id' => 'NE-SMTP-01',
            'workspace_id' => $ws->id,
            'provider_type' => 'smtp',
            'planned_account_count' => 50,
            'domains' => ['example.com'],
            'warmup_start' => '2026-11-01',
            'active_daily_limit' => 20,
        ])->assertStatus(422);

        // Malformed id
        $this->postJson('/api/batches', [
            'batch_id' => 'not valid',
            'workspace_id' => $ws->id,
            'provider_type' => 'smtp',
            'planned_account_count' => 50,
            'domains' => ['example.com'],
            'warmup_start' => '2026-11-01',
            'active_daily_limit' => 20,
        ])->assertStatus(422);
    }

    public function test_batch_cannot_enter_ramp_without_readiness(): void
    {
        Sanctum::actingAs(User::where('email', 'operator@revcadence.local')->first());
        $ws = Workspace::where('code', 'NE')->first();

        // Create a batch whose warmup is already complete but readiness not recorded.
        $create = $this->postJson('/api/batches', [
            'batch_id' => 'NE-SMTP-99',
            'workspace_id' => $ws->id,
            'provider_type' => 'smtp',
            'planned_account_count' => 10,
            'domains' => ['ready-test.example'],
            'warmup_start' => now()->subDays(40)->toDateString(),
            'warmup_days' => 21,
            'active_daily_limit' => 20,
        ])->assertCreated();

        $id = $create->json('data.header.id');

        $this->postJson("/api/batches/{$id}/transition", ['phase' => 'ramp'])
            ->assertStatus(422);
    }
}
