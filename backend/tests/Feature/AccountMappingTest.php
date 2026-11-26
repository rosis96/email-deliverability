<?php

namespace Tests\Feature;

use App\Models\AccountException;
use App\Models\Batch;
use App\Models\BatchProblem;
use App\Models\EmailAccount;
use App\Models\ImportedRecord;
use App\Models\Workspace;
use App\Services\Integrations\AccountMappingService;
use App\Services\ProblemRecorder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function import(Workspace $ws, string $email, string $provider, string $status = 'active'): void
    {
        ImportedRecord::create([
            'source' => 'instantly',
            'entity_type' => 'account',
            'external_record_id' => $email,
            'workspace_id' => $ws->id,
            'batch_mapping_confidence' => 'unmapped',
            'payload' => ['email' => $email, 'provider' => $provider, 'status' => $status, 'daily_limit' => 20],
            'imported_at' => now(),
        ]);
    }

    public function test_live_accounts_replace_audit_counts_and_clear_audit_exceptions(): void
    {
        $ws = Workspace::where('code', 'REV')->first();
        $this->import($ws, 'a@rev-google.com', 'google');
        $this->import($ws, 'b@rev-google.com', 'google', 'connection_error');
        $this->import($ws, 'c@rev-smtp.com', 'custom_smtp');

        $r = app(AccountMappingService::class)->mapWorkspace($ws);
        $this->assertSame(3, $r['mapped']);

        $dfy = Batch::where('batch_id', 'REV-DFY-01')->first();
        $smtp = Batch::where('batch_id', 'REV-SMTP-01')->first();
        $this->assertSame([2, 1, 1], [$dfy->actual_account_count, $dfy->active_account_count, $dfy->error_account_count]);
        $this->assertSame([1, 1, 0], [$smtp->actual_account_count, $smtp->active_account_count, $smtp->error_account_count]);

        // Domains are now populated from mailbox addresses.
        $this->assertSame(1, $dfy->domains()->count());

        // The Oct-30 audit sample exception on REV-DFY-01 is closed once live data exists.
        $this->assertSame(0, AccountException::where('batch_id', $dfy->id)
            ->whereHas('emailAccount', fn ($q) => $q->where('source', 'audit'))
            ->where('status', '!=', 'resolved')->count());

        // Mapping is recorded on the import records.
        $this->assertSame(0, ImportedRecord::where('workspace_id', $ws->id)->whereNull('batch_id')->count());
    }

    public function test_mailboxes_removed_from_instantly_are_pruned_and_recovered_ones_close_exceptions(): void
    {
        $ws = Workspace::where('code', 'REV')->first();
        $this->import($ws, 'a@rev-google.com', 'google', 'connection_error');
        $this->import($ws, 'b@rev-google.com', 'google');
        $svc = app(AccountMappingService::class);
        $svc->mapWorkspace($ws);

        $a = EmailAccount::where('external_id', 'a@rev-google.com')->first();
        $this->assertSame(1, AccountException::where('email_account_id', $a->id)->where('status', 'open')->count());

        // Next sync: a recovered, b deleted from Instantly.
        ImportedRecord::where('external_record_id', 'b@rev-google.com')->delete();
        ImportedRecord::where('external_record_id', 'a@rev-google.com')->first()
            ->update(['payload' => ['email' => 'a@rev-google.com', 'provider' => 'google', 'status' => 'active']]);
        $r = $svc->mapWorkspace($ws);

        $this->assertSame(1, $r['removed']);
        $this->assertSoftDeleted('email_accounts', ['external_id' => 'b@rev-google.com']);
        $this->assertSame(0, AccountException::where('email_account_id', $a->id)->where('status', 'open')->count());
        $dfy = Batch::where('batch_id', 'REV-DFY-01')->first();
        $this->assertSame([1, 1, 0], [$dfy->actual_account_count, $dfy->active_account_count, $dfy->error_account_count]);

        // A mailbox that comes back is restored, not duplicated (unique source+external_id).
        $this->import($ws, 'b@rev-google.com', 'google');
        $svc->mapWorkspace($ws);
        $this->assertSame(1, EmailAccount::withTrashed()->where('external_id', 'b@rev-google.com')->count());
        $this->assertNull(EmailAccount::where('external_id', 'b@rev-google.com')->first()?->deleted_at);
    }

    public function test_problem_recorder_creates_and_auto_resolves_engine_problems(): void
    {
        $batch = Batch::where('batch_id', 'FW-DFY-01')->first();
        $batch->update(['actual_account_count' => 60, 'active_account_count' => 3, 'error_account_count' => 57]);

        $recorder = app(ProblemRecorder::class);
        $r = $recorder->record($batch->fresh());
        $this->assertGreaterThanOrEqual(2, count($r['created']));   // error_pct + error_accounts
        $this->assertSame(0, count($recorder->record($batch->fresh())['created']));  // deduplicated

        // Errors fixed in Instantly -> untouched engine problems auto-resolve.
        $batch->update(['active_account_count' => 60, 'error_account_count' => 0]);
        $r = $recorder->record($batch->fresh());
        $this->assertGreaterThanOrEqual(2, $r['resolved']);
        $this->assertSame(0, BatchProblem::where('batch_id', $batch->id)
            ->whereIn('rule_key', ['error_pct', 'error_accounts'])
            ->whereIn('status', BatchProblem::OPEN_STATES)->count());

        // The manually-seeded reconciliation problem is never auto-closed.
        $this->assertSame('open', BatchProblem::where('problem_code', 'PRB-0001')->first()->status);
    }

    public function test_overview_reports_setup_phase(): void
    {
        $this->actingAs(\App\Models\User::first(), 'sanctum');
        $res = $this->getJson('/api/dashboard/overview')->assertOk();
        $this->assertSame(Batch::where('phase', 'setup')->count(), $res->json('phases.setup'));
    }

    public function test_batch_table_filters_by_workspace_code_or_id(): void
    {
        $this->actingAs(\App\Models\User::first(), 'sanctum');
        $ws = Workspace::where('code', 'BS')->first();
        $this->getJson('/api/dashboard/batches?workspace=BS')->assertOk()->assertJsonPath('data.0.batch_id', 'BS-SMTP-01');
        $this->getJson('/api/dashboard/batches?workspace='.$ws->id)->assertOk()->assertJsonPath('meta.total', 1);
    }
}
