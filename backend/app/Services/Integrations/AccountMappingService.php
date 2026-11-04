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

        $dfyBatch = $batches->firstWhere('provider_type', 'google_dfy_gen2');
        $smtpBatch = $batches->first(fn (Batch $b) => in_array($b->provider_type, ['smtp', 'smtp_maildoso', 'other'], true));
        $single = $batches->count() === 1 ? $batches->first() : null;

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
        $removed = 0;
        $seenEmails = [];
        $limitsByBatch = [];
        $touched = [];

        DB::transaction(function () use ($imports, $batches, $single, $dfyBatch, $smtpBatch, $workspace, &$mapped, &$skipped, &$removed, &$seenEmails, &$limitsByBatch, &$touched) {
            $domainCache = [];

            foreach ($imports as $import) {
                $p = $import->payload;
                $email = $p['email'] ?? $import->external_record_id;
                if (! $email) {
                    $skipped++;
                    continue;
                }

                $target = $single
                    ?? (($p['provider'] ?? null) === 'google' ? ($dfyBatch ?? $smtpBatch) : ($smtpBatch ?? $dfyBatch));
                if (! $target) {
                    $skipped++;
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

                $import->update(['batch_id' => $target->id, 'batch_mapping_confidence' => 'high']);

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
            'removed' => $removed,
        ];
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
                DailyActivity::updateOrCreate(
                    ['batch_id' => $batchId, 'activity_date' => $date],
                    [
                        'workspace_id' => $workspace->id,
                        'active_accounts' => count($v['emails']),
                        'daily_limit_per_account' => $limitByBatch[$batchId] ?? null,
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
