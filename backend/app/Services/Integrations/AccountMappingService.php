<?php

namespace App\Services\Integrations;

use App\Models\AccountException;
use App\Models\Batch;
use App\Models\DailyActivity;
use App\Models\Domain;
use App\Models\EmailAccount;
use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Maps synced Instantly accounts to batches using a principled signal — the
 * Instantly PROVIDER — not domain-name similarity:
 *   - Google provider        -> the workspace's Google DFY Gen 2 batch
 *   - custom SMTP / Maildoso  -> the workspace's SMTP batch
 * Single-batch workspaces receive all their accounts.
 *
 * Batch account counts and the per-account daily limit are then recomputed from
 * the live (instantly-sourced) mailboxes, so the dashboard reflects reality.
 * Runs automatically after every sync (see DeliverabilityRefreshService).
 */
class AccountMappingService
{
    private const EXCEPTION_TYPE = [
        'connection_error' => 'imap_disconnect',
        'sending_error' => 'smtp_auth',
        'soft_bounce_error' => 'other',
    ];

    public function mapWorkspace(Workspace $workspace): array
    {
        $batches = $workspace->batches()->get();
        if ($batches->isEmpty()) {
            return ['mapped' => 0, 'skipped' => 0, 'batches' => 0, 'removed' => 0, 'note' => 'no batches'];
        }

        $byBatchId = $batches->keyBy(fn (Batch $b) => strtoupper($b->batch_id));
        $byId = $batches->keyBy('id');
        // Registered domains (entered when a batch is created) pin mailboxes to that batch.
        $batchByDomain = Domain::where('workspace_id', $workspace->id)->whereNotNull('batch_id')
            ->pluck('batch_id', 'name')->mapWithKeys(fn ($bid, $name) => [strtolower($name) => $bid]);
        $existing = EmailAccount::where('workspace_id', $workspace->id)->where('source', 'instantly')
            ->pluck('batch_id', 'external_id');

        $imports = ImportedRecord::where('source', 'instantly')
            ->where('entity_type', 'account')
            ->where('workspace_id', $workspace->id)
            ->get();

        if ($imports->isEmpty()) {
            // Nothing synced yet — keep the existing (audit) figures rather than zeroing them.
            return ['mapped' => 0, 'skipped' => 0, 'batches' => 0, 'removed' => 0, 'note' => 'no synced accounts'];
        }

        $mapped = 0;
        $skipped = 0;
        $unassigned = 0;
        $removed = 0;
        $seenEmails = [];
        $limitsByBatch = [];
        $touched = [];

        DB::transaction(function () use ($imports, $batches, $byBatchId, $byId, $batchByDomain, $existing, $workspace, &$mapped, &$skipped, &$unassigned, &$removed, &$seenEmails, &$limitsByBatch, &$touched) {
            $domainCache = [];

            foreach ($imports as $import) {
                $p = $import->payload;
                $email = $p['email'] ?? $import->external_record_id;
                if (! $email) {
                    $skipped++;
                    continue;
                }

                [$target, $confidence] = $this->resolveTarget($p, $email, $batches, $byBatchId, $byId, $batchByDomain, $existing);
                if (! $target) {
                    // Ambiguous (e.g. two SMTP batches, no tag, unknown domain): leave it
                    // in the Unassigned list for an operator instead of guessing.
                    $import->update(['batch_id' => null, 'batch_mapping_confidence' => 'unmapped']);
                    $seenEmails[] = $email;
                    $unassigned++;
                    continue;
                }

                [$status, $connection] = $this->mapStatus($p['status'] ?? null);
                $domain = $this->domainFor($workspace, $target, $email, $p['provider'] ?? null, $domainCache);

                // withTrashed: the (source, external_id) unique index also covers
                // soft-deleted rows, so a mailbox that comes back must be restored.
                $account = EmailAccount::withTrashed()->firstOrNew(['source' => 'instantly', 'external_id' => $email]);
                $account->fill([
                    'workspace_id' => $workspace->id,
                    'batch_id' => $target->id,
                    'domain_id' => $domain?->id,
                    'email' => $email,
                    'provider' => $p['provider'] ?? null,
                    'status' => $status,
                    'connection_status' => $connection,
                    'warmup_enabled' => ($p['warmup_status'] ?? null) === 1,
                    'last_error' => $this->errorText($p),
                    'last_synced_at' => now(),
                ]);
                $account->deleted_at = null;
                $account->save();
                $seenEmails[] = $email;

                $import->update(['batch_id' => $target->id, 'batch_mapping_confidence' => $confidence]);

                if ($status === 'error') {
                    $this->recordException($target, $account, $p);
                } else {
                    // Account recovered in Instantly — close its open exceptions.
                    $this->resolveExceptions(AccountException::where('email_account_id', $account->id));
                }

                if (is_numeric($p['daily_limit'] ?? null)) {
                    $limitsByBatch[$target->id][] = (int) $p['daily_limit'];
                }

                $mapped++;
                $touched[$target->id] = true;
            }

            // Mailboxes no longer present in Instantly: soft-delete and close their exceptions.
            $gone = EmailAccount::where('workspace_id', $workspace->id)
                ->where('source', 'instantly')
                ->whereNotIn('external_id', $seenEmails ?: ['__none__'])
                ->get();
            foreach ($gone as $account) {
                $this->resolveExceptions(AccountException::where('email_account_id', $account->id));
                $account->delete();
                $removed++;
            }

            // Live data now backs this workspace, so the one-off Oct-30 audit
            // sample exceptions no longer describe current state.
            $this->resolveExceptions(
                AccountException::where('workspace_id', $workspace->id)
                    ->whereHas('emailAccount', fn ($q) => $q->withTrashed()->where('source', 'audit'))
            );

            // Recompute EVERY batch in the workspace, including ones that ended up
            // with zero live mailboxes — otherwise stale audit counts survive.
            foreach ($batches as $batch) {
                $limits = $limitsByBatch[$batch->id] ?? [];
                $avgLimit = count($limits) ? (int) round(array_sum($limits) / count($limits)) : null;
                $this->recomputeBatch($batch, $avgLimit);
            }
        });

        return [
            'mapped' => $mapped,
            'skipped' => $skipped,
            'batches' => count($touched),
            'unassigned' => $unassigned,
            'removed' => $removed,
        ];
    }

    /**
     * Decide which batch a mailbox belongs to, most to least certain:
     *   1. an Instantly tag whose label equals a batch ID (e.g. "REV-SMTP-02")  -> high
     *   2. it was already in a batch (sticky — never silently moves)            -> medium
     *   3. its domain is registered on a batch (new mailboxes of a new batch)   -> high
     *   4. the workspace has exactly ONE batch for its provider type            -> medium
     *   5. otherwise unassigned (null)
     *
     * @return array{0: ?Batch, 1: string}
     */
    private function resolveTarget(array $p, string $email, $batches, $byBatchId, $byId, $batchByDomain, $existing): array
    {
        foreach ($p['tags'] ?? [] as $tag) {
            $label = strtoupper(trim((string) ($tag['label'] ?? '')));
            if ($label !== '' && isset($byBatchId[$label])) {
                return [$byBatchId[$label], 'high'];
            }
        }

        if (isset($existing[$email], $byId[$existing[$email]])) {
            return [$byId[$existing[$email]], 'medium'];
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain !== '' && isset($batchByDomain[$domain], $byId[$batchByDomain[$domain]])) {
            return [$byId[$batchByDomain[$domain]], 'high'];
        }

        if ($batches->count() === 1) {
            return [$batches->first(), 'medium'];
        }

        $isGoogle = ($p['provider'] ?? null) === 'google';
        $candidates = $batches->filter(fn (Batch $b) => $isGoogle
            ? $b->provider_type === 'google_dfy_gen2'
            : $b->provider_type !== 'google_dfy_gen2')
            ->filter(fn (Batch $b) => ! in_array($b->phase, ['retired'], true));
        if ($candidates->count() === 1) {
            return [$candidates->first(), 'medium'];
        }

        return [null, 'unmapped'];
    }

    /**
     * Operator assignment: put these mailboxes in $batch (app side). Returns the
     * previous batch id for each mailbox that moved, so callers can untag it.
     *
     * @param  array<int,string>  $emails
     * @return array<string,string> email => previous batch id
     */
    public function assignToBatch(Batch $batch, array $emails): array
    {
        $previous = [];
        $touched = [$batch->id => $batch];

        DB::transaction(function () use ($batch, $emails, &$previous, &$touched) {
            $imports = ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
                ->where('workspace_id', $batch->workspace_id)->whereIn('external_record_id', $emails)->get();
            $domainCache = [];

            foreach ($imports as $import) {
                $p = $import->payload ?? [];
                $email = $import->external_record_id;
                if ($import->batch_id && $import->batch_id !== $batch->id) {
                    $previous[$email] = $import->batch_id;
                    $touched[$import->batch_id] = Batch::find($import->batch_id);
                }

                [$status, $connection] = $this->mapStatus($p['status'] ?? null);
                $domain = $this->domainFor($batch->workspace, $batch, $email, $p['provider'] ?? null, $domainCache);
                if ($domain && $domain->batch_id !== $batch->id) {
                    $domain->update(['batch_id' => $batch->id]);
                }
                $account = EmailAccount::withTrashed()->firstOrNew(['source' => 'instantly', 'external_id' => $email]);
                $account->fill([
                    'workspace_id' => $batch->workspace_id,
                    'batch_id' => $batch->id,
                    'domain_id' => $domain?->id,
                    'email' => $email,
                    'provider' => $p['provider'] ?? null,
                    'status' => $status,
                    'connection_status' => $connection,
                    'warmup_enabled' => ($p['warmup_status'] ?? null) === 1,
                    'last_error' => $this->errorText($p),
                    'last_synced_at' => $account->last_synced_at ?? now(),
                ]);
                $account->deleted_at = null;
                $account->save();

                // Exceptions follow the mailbox to its new batch.
                AccountException::where('email_account_id', $account->id)->update(['batch_id' => $batch->id]);
                $import->update(['batch_id' => $batch->id, 'batch_mapping_confidence' => 'high']);
            }

            foreach (array_filter($touched) as $b) {
                $this->recomputeBatch($b, null);
            }
        });

        return $previous;
    }

    /** Find/create the Domain row for a mailbox so batches show real domain counts. */
    private function domainFor(Workspace $workspace, Batch $batch, string $email, ?string $provider, array &$cache): ?Domain
    {
        $name = strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));
        if ($name === '') {
            return null;
        }
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $domain = Domain::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => $name],
            ['batch_id' => $batch->id, 'provider' => $provider],
        );
        if (! $domain->batch_id) {
            $domain->update(['batch_id' => $batch->id]);
        }

        return $cache[$name] = $domain;
    }

    private function resolveExceptions($query): void
    {
        $query->where('status', '!=', 'resolved')->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recomputeBatch(Batch $batch, ?int $avgLimit): void
    {
        $accounts = EmailAccount::where('batch_id', $batch->id)->where('source', 'instantly')->get();

        $update = [
            'actual_account_count' => $accounts->count(),
            'active_account_count' => $accounts->where('status', 'active')->count(),
            'error_account_count' => $accounts->where('status', 'error')->count(),
        ];
        // Set the per-account daily limit from live data only if not already set by an operator.
        if ($avgLimit && ! $batch->active_daily_limit) {
            $update['active_daily_limit'] = $avgLimit;
        }
        $batch->update($update);
    }

    private function recordException(Batch $batch, EmailAccount $account, array $p): void
    {
        $type = self::EXCEPTION_TYPE[$p['status'] ?? ''] ?? 'other';
        AccountException::updateOrCreate(
            ['batch_id' => $batch->id, 'email_account_id' => $account->id, 'type' => $type],
            [
                'workspace_id' => $batch->workspace_id,
                'severity' => 'medium',
                'symptom' => 'Instantly status: '.str_replace('_', ' ', (string) ($p['status'] ?? 'error')),
                'sample_dialog' => isset($p['status_message']) ? substr((string) json_encode($p['status_message']), 0, 500) : null,
                'status' => 'open',
                'detected_at' => now(),
            ],
        );
    }

    /** @return array{0:string,1:?string} [status, connection_status] */
    private function mapStatus(?string $s): array
    {
        return match ($s) {
            'active' => ['active', 'connected'],
            'paused' => ['paused', 'connected'],
            'maintenance' => ['active', 'connected'],
            'connection_error' => ['error', 'disconnected'],
            'sending_error', 'soft_bounce_error' => ['error', 'connected'],
            default => ['active', null],
        };
    }

    private function errorText(array $p): ?string
    {
        if (($p['status'] ?? 'active') === 'active') {
            return null;
        }

        return 'Instantly: '.str_replace('_', ' ', (string) ($p['status'] ?? '')).' (sanitised; no credentials stored).';
    }

    /**
     * Pull per-account daily analytics for a workspace and aggregate into
     * batch-level daily_activity (sent/bounces/replies). Real sending data.
     */
    public function pullDailyActivity(IntegrationConnection $conn, int $days = 30): int
    {
        $workspace = $conn->workspace;
        if (! $workspace) {
            return 0;
        }

        $accounts = EmailAccount::where('workspace_id', $workspace->id)
            ->where('source', 'instantly')
            ->whereNotNull('batch_id')
            ->get(['email', 'batch_id']);
        if ($accounts->isEmpty()) {
            return 0;
        }

        $batchByEmail = $accounts->pluck('batch_id', 'email');
        $limitByBatch = Batch::whereIn('id', $accounts->pluck('batch_id')->unique())->pluck('active_daily_limit', 'id');
        // Each mailbox's own Instantly daily limit (from the latest sync), so
        // capacity is the true sum rather than count x an averaged limit.
        $limitByEmail = ImportedRecord::where('source', 'instantly')
            ->where('entity_type', 'account')
            ->where('workspace_id', $workspace->id)
            ->get(['external_record_id', 'payload'])
            ->mapWithKeys(fn ($r) => [$r->external_record_id => is_numeric($r->payload['daily_limit'] ?? null) ? (int) $r->payload['daily_limit'] : null]);
        $emails = $accounts->pluck('email')->all();

        $end = CarbonImmutable::now();
        $start = $end->subDays($days - 1);

        $client = new InstantlyClient($conn->apiKey() ?: (string) env('INSTANTLY_API_KEY'));
        $rows = $client->accountsDailyAnalytics($emails, $start->toDateString(), $end->toDateString());

        $agg = [];
        foreach ($rows as $r) {
            $email = $r['email_account'] ?? null;
            $date = $r['date'] ?? null;
            if (! $email || ! $date || ! isset($batchByEmail[$email])) {
                continue;
            }
            $bid = $batchByEmail[$email];
            $agg[$bid][$date] ??= ['sent' => 0, 'bounced' => 0, 'replies' => 0, 'emails' => []];
            $agg[$bid][$date]['sent'] += (int) ($r['sent'] ?? 0);
            $agg[$bid][$date]['bounced'] += (int) ($r['bounced'] ?? 0);
            $agg[$bid][$date]['replies'] += (int) ($r['replies'] ?? 0);
            if ((int) ($r['sent'] ?? 0) > 0) {
                $agg[$bid][$date]['emails'][$email] = true;
            }
        }

        $written = 0;
        foreach ($agg as $batchId => $byDate) {
            foreach ($byDate as $date => $v) {
                $active = count($v['emails']);
                $fallback = $limitByBatch[$batchId] ?? null;
                $capacity = null;
                if ($active > 0) {
                    $capacity = 0;
                    foreach (array_keys($v['emails']) as $email) {
                        $limit = $limitByEmail[$email] ?? $fallback;
                        if ($limit === null) {
                            $capacity = null;   // unknown limit -> capacity unknown, never guessed
                            break;
                        }
                        $capacity += $limit;
                    }
                }

                DailyActivity::updateOrCreate(
                    ['batch_id' => $batchId, 'activity_date' => $date],
                    [
                        'workspace_id' => $workspace->id,
                        'active_accounts' => $active,
                        // Average of the SENDING mailboxes' limits (display only); capacity below is exact.
                        'daily_limit_per_account' => ($capacity !== null && $active > 0) ? (int) round($capacity / $active) : $fallback,
                        'daily_capacity' => $capacity,
                        'sent' => $v['sent'],
                        'replies' => $v['replies'],
                        'bounces' => $v['bounced'],
                        'source' => 'instantly',
                    ],
                );
                $written++;
            }
        }

        return $written;
    }
}
