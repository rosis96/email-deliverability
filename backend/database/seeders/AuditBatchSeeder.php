<?php

namespace Database\Seeders;

use App\Models\AccountException;
use App\Models\ApplicationSetting;
use App\Models\Batch;
use App\Models\BatchLifecycleEvent;
use App\Models\BatchProblem;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\PlacementTest;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeds the VERIFIED October 30, 2026 audit snapshot (PRD §18).
 *
 * These are historical baseline values, NOT live data. Warmup start dates,
 * active start dates and batch-level sending splits are UNKNOWN and are left
 * null — never invented. Batches are registered in "setup" (awaiting verified
 * lifecycle dates); none is marked "active" merely because accounts connect.
 */
class AuditBatchSeeder extends Seeder
{
    private const AUDIT_DATE = '2026-10-30';

    public function run(): void
    {
        $ws = Workspace::pluck('id', 'code');

        // [code, batch_id, provider_type, total, active, errors]
        $batches = [
            ['NE',  'NE-SMTP-01',  'smtp',             187, 187, 0],
            ['UNI', 'UNI-SMTP-01', 'smtp',             199, 197, 2],
            ['UNI', 'UNI-DFY-01',  'google_dfy_gen2',  66,  66,  0],
            ['REV', 'REV-SMTP-01', 'smtp',             328, 328, 0],
            ['REV', 'REV-DFY-01',  'google_dfy_gen2',  72,  0,   72],
            ['FB',  'FB-SMTP-01',  'smtp',             149, 149, 0],
            ['FB',  'FB-DFY-01',   'google_dfy_gen2',  39,  39,  0],
            ['BS',  'BS-SMTP-01',  'smtp',             200, 200, 0],
            ['FW',  'FW-SMTP-01',  'smtp_maildoso',    70,  70,  0],
            ['FW',  'FW-DFY-01',   'google_dfy_gen2',  60,  3,   57],
            ['RES', 'RES-SMTP-01', 'smtp',             194, 194, 0],
        ];

        foreach ($batches as [$code, $batchId, $provider, $total, $active, $errors]) {
            $batch = Batch::updateOrCreate(
                ['batch_id' => $batchId],
                [
                    'workspace_id' => $ws[$code],
                    'provider_type' => $provider,
                    'planned_account_count' => $total,
                    'actual_account_count' => $total,
                    'active_account_count' => $active,
                    'error_account_count' => $errors,
                    // Unknown — never backfilled.
                    'warmup_start' => null,
                    'active_start' => null,
                    'active_daily_limit' => null,
                    'phase' => 'setup',
                    'status' => 'active',
                    'notes' => 'Verified audit snapshot ('.self::AUDIT_DATE.'). Warmup/active dates and sending splits unknown — awaiting verification before lifecycle progression.',
                ],
            );

            BatchLifecycleEvent::firstOrCreate(
                ['batch_id' => $batch->id, 'phase' => 'setup'],
                ['from_phase' => null, 'entered_at' => CarbonImmutable::parse(self::AUDIT_DATE), 'note' => 'Registered from audit snapshot.'],
            );
        }

        $this->seedCampaignsAndPlacement($ws);
        $this->seedExceptions();
        $this->seedDiscrepancies($ws);
    }

    private function seedCampaignsAndPlacement(\Illuminate\Support\Collection $ws): void
    {
        // New Edge & Big Smile: all campaigns in Draft, and no placement tests.
        foreach (['NE' => 'NE-SMTP-01', 'BS' => 'BS-SMTP-01'] as $code => $batchId) {
            $batch = Batch::where('batch_id', $batchId)->first();
            foreach (['Outreach Q4', 'Follow-up Sequence'] as $name) {
                Campaign::firstOrCreate(
                    ['workspace_id' => $ws[$code], 'batch_id' => $batch->id, 'name' => $name],
                    ['status' => 'draft'],
                );
            }
        }

        // Existing placement tests — kept at their ORIGINAL workspace scope (batch_id null).
        $placements = [
            ['UNI', '2026-10-30', 56.48, 'Unicorn workspace-level placement test. Original scope: workspace.'],
            ['UNI', '2026-10-28', 24.09, 'Unicorn workspace-level placement test. Original scope: workspace.'],
            ['REV', '2026-10-30', 18.98, 'Revcadence workspace-level placement test. Original scope: workspace.'],
        ];
        foreach ($placements as [$code, $date, $spam, $evidence]) {
            PlacementTest::firstOrCreate(
                ['workspace_id' => $ws[$code], 'test_date' => $date, 'spam_pct' => $spam],
                ['scope' => 'workspace', 'batch_id' => null, 'source' => 'audit', 'evidence' => $evidence],
            );
        }
    }

    private function seedExceptions(): void
    {
        // Unicorn SMTP: two disconnected accounts, similar password/IMAP pattern.
        $uni = Batch::where('batch_id', 'UNI-SMTP-01')->first();
        for ($i = 1; $i <= 2; $i++) {
            $acct = EmailAccount::firstOrCreate(
                ['source' => 'audit', 'external_id' => "UNI-SMTP-01-disc-{$i}"],
                [
                    'workspace_id' => $uni->workspace_id,
                    'batch_id' => $uni->id,
                    'email' => "disconnected{$i}@unicorn-audit.example",
                    'status' => 'disconnected',
                    'connection_status' => 'disconnected',
                    'imap_status' => 'not_configured',
                    'last_error' => 'Password/IMAP authentication issue (sanitised; no credentials stored).',
                ],
            );
            AccountException::firstOrCreate(
                ['batch_id' => $uni->id, 'email_account_id' => $acct->id, 'type' => 'imap_disconnect'],
                [
                    'workspace_id' => $uni->workspace_id,
                    'severity' => 'medium',
                    'symptom' => 'Account disconnected — password/IMAP pattern.',
                    'sample_dialog' => 'Password updated; IMAP not configured (sanitised).',
                    'status' => 'open',
                    'detected_at' => CarbonImmutable::parse(self::AUDIT_DATE),
                ],
            );
        }

        // Revcadence DFY & Futureworks DFY: one sample dialog each.
        foreach (['REV-DFY-01', 'FW-DFY-01'] as $batchId) {
            $batch = Batch::where('batch_id', $batchId)->first();
            $acct = EmailAccount::firstOrCreate(
                ['source' => 'audit', 'external_id' => "{$batchId}-sample-dialog"],
                [
                    'workspace_id' => $batch->workspace_id,
                    'batch_id' => $batch->id,
                    'email' => 'sample@'.strtolower($batchId).'-audit.example',
                    'status' => 'error',
                    'connection_status' => 'disconnected',
                    'imap_status' => 'not_configured',
                    'last_error' => 'Password updated; IMAP not configured; detected cancelled order status (sanitised).',
                ],
            );
            AccountException::firstOrCreate(
                ['batch_id' => $batch->id, 'email_account_id' => $acct->id, 'type' => 'cancelled_order'],
                [
                    'workspace_id' => $batch->workspace_id,
                    'severity' => 'high',
                    'symptom' => 'Password updated, IMAP not configured, cancelled order status detected.',
                    'sample_dialog' => 'Only one dialog per batch was inspected during the audit.',
                    'status' => 'open',
                    'detected_at' => CarbonImmutable::parse(self::AUDIT_DATE),
                ],
            );
        }
    }

    private function seedDiscrepancies(\Illuminate\Support\Collection $ws): void
    {
        // Revcadence 408 (Oct 29) -> 400 (Oct 30); 8 reconnecting accounts missing.
        BatchProblem::firstOrCreate(
            ['problem_code' => 'PRB-0001'],
            [
                'workspace_id' => $ws['REV'],
                'batch_id' => null,
                'rule_key' => 'reconciliation',
                'severity' => 'medium',
                'symptom' => 'Revcadence account count changed from 408 (Oct 29) to 400 (Oct 30). Eight previously observed reconnecting accounts are missing from the later view.',
                'evidence' => 'Audit comparison 2026-10-29 vs 2026-10-30.',
                'root_cause_status' => 'unknown',
                'status' => 'open',
                'detected_at' => CarbonImmutable::parse(self::AUDIT_DATE),
            ],
        );

        // Header totals vs per-campaign sums differ in five workspaces.
        ApplicationSetting::updateOrCreate(
            ['key' => 'known_discrepancies'],
            [
                'group' => 'provenance',
                'description' => 'Reconciliation notes preserved from the audit.',
                'value' => ['value' => [
                    'instantly_header_vs_campaign' => 'Instantly report header totals and per-campaign table sums differ in five workspaces. Per-campaign table sums are the PRIMARY reporting figure; header totals are preserved separately with their exact labels and must not be discarded.',
                    'revcadence_count_delta' => '408 (Oct 29) vs 400 (Oct 30); 8 reconnecting accounts unaccounted for. Logged as PRB-0001 (unresolved).',
                ]],
            ],
        );
    }
}
