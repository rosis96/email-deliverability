<?php

namespace App\Services\Integrations;

use App\Models\Campaign;
use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Models\IntegrationSyncLog;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Orchestrates read-only Instantly sync for a single workspace connection.
 *
 * Accounts are imported as provenance records. New mailboxes start "unmapped";
 * AccountMappingService then assigns them to batches by Instantly provider (never
 * by domain-name similarity). Existing mappings survive re-syncs, and mailboxes
 * that disappear from Instantly are pruned.
 */
class InstantlySyncService
{
    public const ACCOUNT_STATUS = [
        1 => 'active',
        2 => 'paused',
        3 => 'maintenance',
        -1 => 'connection_error',
        -2 => 'soft_bounce_error',
        -3 => 'sending_error',
    ];

    public const PROVIDER = [
        1 => 'custom_smtp', 2 => 'google', 3 => 'microsoft', 4 => 'aws', 8 => 'airmail', 11 => 'airmail_instant',
    ];

    public const CAMPAIGN_STATUS = [
        0 => 'draft', 1 => 'active', 2 => 'paused', 3 => 'completed', 4 => 'running_subsequences',
        -1 => 'accounts_unhealthy', -2 => 'bounce_protect', -99 => 'account_suspended',
    ];

    private function client(IntegrationConnection $conn): InstantlyClient
    {
        $key = $conn->apiKey() ?: (string) env('INSTANTLY_API_KEY');
        if (! $key) {
            throw new RuntimeException('No Instantly API key configured for this connection.');
        }

        return new InstantlyClient($key);
    }

    /** Verify the key and record which workspace it belongs to. */
    public function verify(IntegrationConnection $conn): array
    {
        $ws = $this->client($conn)->currentWorkspace();

        $conn->status = 'connected';
        $conn->last_success_at = now();
        $conn->last_error = null;
        $conn->config = array_merge($conn->config ?? [], [
            'workspace_external_id' => $ws['id'] ?? null,
            'workspace_name' => $ws['name'] ?? null,
            'plan_id' => $ws['plan_id'] ?? null,
        ]);
        $conn->save();

        if ($conn->workspace) {
            $conn->workspace->update([
                'external_instantly_id' => $ws['id'] ?? $conn->workspace->external_instantly_id,
                'integration_status' => 'connected',
            ]);
        }

        return $ws;
    }

    /** Pull accounts + campaigns for the connection's workspace. */
    public function sync(IntegrationConnection $conn): IntegrationSyncLog
    {
        $log = IntegrationSyncLog::create([
            'integration_connection_id' => $conn->id,
            'provider' => 'instantly',
            'status' => 'partial',
            'started_at' => now(),
        ]);

        try {
            $this->verify($conn);
            $client = $this->client($conn);
            $workspace = $conn->workspace;

            $accounts = $client->allAccounts();
            $this->importAccounts($workspace, $accounts);

            $campaigns = $client->allCampaigns();
            $this->importCampaigns($workspace, $campaigns);

            $observed = $this->tally($accounts);
            $conn->config = array_merge($conn->config ?? [], [
                'observed' => $observed,
                'campaigns_count' => count($campaigns),
                'synced_at' => now()->toIso8601String(),
            ]);
            $conn->last_success_at = now();
            $conn->save();

            if ($workspace) {
                $workspace->update(['last_synced_at' => now(), 'integration_status' => 'connected']);
            }

            $log->update([
                'status' => 'success',
                'records_processed' => count($accounts) + count($campaigns),
                'message' => sprintf(
                    '%d accounts (%d active, %d error), %d campaigns synced.',
                    $observed['total'], $observed['active'], $observed['error'], count($campaigns),
                ),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $conn->update([
                'status' => $this->statusFromError($e),
                'last_error' => $e->getMessage(),
            ]);
            $log->update(['status' => 'failed', 'message' => $e->getMessage(), 'finished_at' => now()]);
            throw $e;
        }

        return $log;
    }

    private function importAccounts(?Workspace $workspace, array $accounts): void
    {
        $seen = [];
        foreach ($accounts as $a) {
            $email = $a['email'] ?? null;
            if (! $email) {
                continue;
            }
            $seen[] = $email;

            $record = ImportedRecord::firstOrNew(
                ['source' => 'instantly', 'entity_type' => 'account', 'external_record_id' => $email],
            );
            if (! $record->exists || $record->workspace_id !== $workspace?->id) {
                // New (or moved) mailbox: starts unmapped until AccountMappingService runs.
                // Existing mappings are preserved — re-syncing must not wipe them.
                $record->batch_id = null;
                $record->batch_mapping_confidence = 'unmapped';
            }
            $record->workspace_id = $workspace?->id;
            $record->payload = $this->sanitizeAccount($a);
            $record->imported_at = now();
            $record->save();
        }

        // Mailboxes removed from Instantly must not linger in counts.
        if ($workspace) {
            ImportedRecord::where('source', 'instantly')
                ->where('entity_type', 'account')
                ->where('workspace_id', $workspace->id)
                ->whereNotIn('external_record_id', $seen ?: ['__none__'])
                ->delete();
        }
    }

    private function importCampaigns(?Workspace $workspace, array $campaigns): void
    {
        if (! $workspace) {
            return;
        }
        foreach ($campaigns as $c) {
            if (empty($c['id'])) {
                continue;
            }
            Campaign::updateOrCreate(
                ['workspace_id' => $workspace->id, 'external_id' => $c['id']],
                [
                    'name' => $c['name'] ?? '(unnamed)',
                    'status' => self::CAMPAIGN_STATUS[$c['status'] ?? 0] ?? 'draft',
                    'batch_id' => null,
                ],
            );
        }
    }

    /** Keep only non-sensitive, useful fields as provenance. */
    private function sanitizeAccount(array $a): array
    {
        $statusNum = $a['status'] ?? null;

        return [
            'email' => $a['email'] ?? null,
            // Instantly's own account id (if returned) — used for tag toggling.
            'id' => $a['id'] ?? null,
            // Tags drive batch membership: a tag whose label equals a batch ID.
            'tags' => array_values(array_map(
                fn (array $t) => ['id' => $t['id'] ?? null, 'label' => $t['label'] ?? ''],
                array_filter($a['tags'] ?? [], 'is_array'),
            )),
            'status_code' => $statusNum,
            'status' => self::ACCOUNT_STATUS[$statusNum] ?? 'unknown',
            'warmup_status' => $a['warmup_status'] ?? null,
            'provider' => self::PROVIDER[$a['provider_code'] ?? null] ?? null,
            'daily_limit' => $a['daily_limit'] ?? null,
            'setup_pending' => $a['setup_pending'] ?? null,
            'stat_warmup_score' => $a['stat_warmup_score'] ?? null,
            'timestamp_warmup_start' => $a['timestamp_warmup_start'] ?? null,
            'timestamp_created' => $a['timestamp_created'] ?? null,
            // status_message may carry provider error detail (no credentials).
            'status_message' => $a['status_message'] ?? null,
        ];
    }

    private function tally(array $accounts): array
    {
        $active = $error = $paused = 0;
        foreach ($accounts as $a) {
            $s = $a['status'] ?? null;
            if ($s === 1) {
                $active++;
            } elseif ($s !== null && $s < 0) {
                $error++;
            } elseif ($s === 2) {
                $paused++;
            }
        }

        return ['total' => count($accounts), 'active' => $active, 'error' => $error, 'paused' => $paused];
    }

    public function statusFromError(Throwable $e): string
    {
        $m = $e->getMessage();
        if (str_contains($m, '401')) {
            return 'disconnected';
        }
        if (str_contains($m, '403')) {
            return 'permission_denied';
        }

        return 'sync_failed';
    }
}
