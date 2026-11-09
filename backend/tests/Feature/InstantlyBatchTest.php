<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchOperation;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Integrations\AccountMappingService;
use App\Services\Integrations\InstantlyBatchService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstantlyBatchTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private array $campaignList = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->ws = Workspace::where('code', 'REV')->first();
        IntegrationConnection::updateOrCreate(
            ['provider' => 'instantly', 'workspace_id' => $this->ws->id],
            ['credentials' => 'test-key-1234567890', 'status' => 'connected'],
        );
        // No throttling in tests.
        $svc = app(InstantlyBatchService::class);
        $svc->throttleMicros = 0;
        $this->app->instance(InstantlyBatchService::class, $svc);
        Sanctum::actingAs(User::where('email', 'admin@revcadence.local')->first());
    }

    private function newSmtpBatch(string $id = 'REV-SMTP-02'): Batch
    {
        return Batch::create([
            'batch_id' => $id, 'workspace_id' => $this->ws->id, 'provider_type' => 'smtp',
            'phase' => 'warmup', 'status' => 'active', 'active_daily_limit' => 15,
        ]);
    }

    private function import(string $email, array $tags = [], string $provider = 'custom_smtp', string $status = 'active'): void
    {
        ImportedRecord::create([
            'source' => 'instantly', 'entity_type' => 'account', 'external_record_id' => $email,
            'workspace_id' => $this->ws->id, 'batch_mapping_confidence' => 'unmapped',
            'payload' => ['email' => $email, 'provider' => $provider, 'status' => $status, 'daily_limit' => 5,
                'tags' => array_map(fn ($l) => ['id' => 'tag-'.$l, 'label' => $l], $tags)],
            'imported_at' => now(),
        ]);
    }

    private function fakeInstantly(): void
    {
        Http::fake(function (Request $r) {
            $url = $r->url();
            if (str_contains($url, '/custom-tags/toggle-resource')) {
                return Http::response(['success' => true]);
            }
            if (str_contains($url, '/custom-tags') && $r->method() === 'GET') {
                return Http::response(['items' => [['id' => 'tag-REV-SMTP-01', 'label' => 'REV-SMTP-01']], 'next_starting_after' => null]);
            }
            if (str_contains($url, '/custom-tags')) {
                return Http::response(['id' => 'tag-new', 'label' => $r['label']]);
            }
            if (str_contains($url, '/campaigns/c1') && $r->method() === 'GET') {
                return Http::response(['id' => 'c1', 'name' => 'Outbound Q4', 'email_list' => $this->campaignList]);
            }
            if (str_contains($url, '/campaigns/c1') && $r->method() === 'PATCH') {
                $this->campaignList = $r['email_list'];

                return Http::response(['id' => 'c1', 'email_list' => $this->campaignList]);
            }

            return Http::response(['ok' => true]);   // warmup, pause/resume, account patch
        });
    }

    public function test_tags_decide_batch_and_ambiguous_mailboxes_are_left_unassigned(): void
    {
        $new = $this->newSmtpBatch();
        $this->import('t1@new-domain.com', ['REV-SMTP-02']);       // tag -> new batch
        $this->import('x1@unknown-domain.com');                    // 2 SMTP batches, no tag -> unassigned

        $r = app(AccountMappingService::class)->mapWorkspace($this->ws);

        $this->assertSame(1, $r['unassigned']);
        $this->assertSame($new->id, EmailAccount::where('external_id', 't1@new-domain.com')->value('batch_id'));
        $this->assertNull(ImportedRecord::where('external_record_id', 'x1@unknown-domain.com')->value('batch_id'));

        $this->getJson("/api/workspaces/{$this->ws->id}/unassigned-accounts")
            ->assertOk()->assertJsonPath('data.0.email', 'x1@unknown-domain.com');
    }

    public function test_assign_moves_mailbox_and_tags_it_in_instantly(): void
    {
        $this->fakeInstantly();
        $new = $this->newSmtpBatch();
        $this->import('x1@unknown-domain.com');
        app(AccountMappingService::class)->mapWorkspace($this->ws);

        $res = $this->postJson("/api/batches/{$new->id}/assign", ['emails' => ['x1@unknown-domain.com']])->assertStatus(202);
        $op = BatchOperation::find($res->json('data.id'));

        $this->assertSame('completed', $op->status, implode("\n", $op->log ?? []));
        $this->assertSame($new->id, EmailAccount::where('external_id', 'x1@unknown-domain.com')->value('batch_id'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'toggle-resource') && $r['assign'] === true && $r['resource_type'] === 1);

        // Sticky: the next sync keeps it there even without a tag.
        app(AccountMappingService::class)->mapWorkspace($this->ws);
        $this->assertSame($new->id, EmailAccount::where('external_id', 'x1@unknown-domain.com')->value('batch_id'));
    }

    public function test_resting_stage_pulls_batch_from_campaigns_and_turns_warmup_on(): void
    {
        $this->fakeInstantly();
        $smtp = Batch::where('batch_id', 'REV-SMTP-01')->first();
        $this->import('a@rev.com');
        $this->import('b@rev.com');
        app(AccountMappingService::class)->mapWorkspace($this->ws);
        Campaign::create(['workspace_id' => $this->ws->id, 'external_id' => 'c1', 'name' => 'Outbound Q4', 'status' => 'active']);
        $this->campaignList = ['a@rev.com', 'b@rev.com', 'other@elsewhere.com'];

        $preview = $this->postJson("/api/batches/{$smtp->id}/instantly/preview", ['type' => 'stage', 'phase' => 'resting'])->assertOk();
        $this->assertSame(2, $preview->json('data.campaigns.0.accounts_in_campaign'));

        $res = $this->postJson("/api/batches/{$smtp->id}/instantly/apply", ['type' => 'stage', 'phase' => 'resting'])->assertStatus(202);
        $op = BatchOperation::find($res->json('data.id'));

        $this->assertSame('completed', $op->status, implode("\n", $op->log ?? []));
        $this->assertSame(['other@elsewhere.com'], $this->campaignList);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/accounts/warmup/enable'));
        $this->assertSame('resting', $smtp->fresh()->phase);
    }

    public function test_rotation_swaps_mailboxes_in_campaigns(): void
    {
        $this->fakeInstantly();
        $old = Batch::where('batch_id', 'REV-SMTP-01')->first();
        $new = $this->newSmtpBatch();
        $this->import('a@rev.com');
        app(AccountMappingService::class)->assignToBatch($old, ['a@rev.com']);
        $this->import('n@new.com', ['REV-SMTP-02']);
        app(AccountMappingService::class)->mapWorkspace($this->ws);
        Campaign::create(['workspace_id' => $this->ws->id, 'external_id' => 'c1', 'name' => 'Outbound Q4', 'status' => 'active']);
        $this->campaignList = ['a@rev.com', 'keep@x.com'];

        $res = $this->postJson("/api/batches/{$old->id}/instantly/apply", ['type' => 'rotate', 'to_batch_id' => $new->id])->assertStatus(202);
        $op = BatchOperation::find($res->json('data.id'));

        $this->assertSame('completed', $op->status, implode("\n", $op->log ?? []));
        $this->assertEqualsCanonicalizing(["keep@x.com", "n@new.com"], $this->campaignList);
        $this->assertSame('resting', $old->fresh()->phase);
        $this->assertSame('active', $new->fresh()->phase);
    }

    public function test_viewer_cannot_apply(): void
    {
        Sanctum::actingAs(User::where('email', 'viewer@revcadence.local')->first());
        $smtp = Batch::where('batch_id', 'REV-SMTP-01')->first();
        $this->postJson("/api/batches/{$smtp->id}/instantly/apply", ['type' => 'stage', 'phase' => 'resting'])->assertForbidden();
    }
}
