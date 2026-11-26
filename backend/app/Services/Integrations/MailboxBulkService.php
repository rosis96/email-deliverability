<?php

namespace App\Services\Integrations;

use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Models\MailboxOperation;
use App\Models\Workspace;
use App\Services\AuditLogger;
use RuntimeException;
use Throwable;

/**
 * Health overview of every mailbox by workspace and tag, plus bulk edits
 * applied to Instantly for a selection, a whole tag, or a filter.
 */
class MailboxBulkService
{
    /** Bulk actions => human label. */
    public const ACTIONS = [
        'daily_limit' => 'Set daily sending limit',
        'sending_gap' => 'Set sending gap (minutes)',
        'enable_slow_ramp' => 'Slow ramp on/off',
        'warmup_on' => 'Turn warmup ON',
        'warmup_off' => 'Turn warmup OFF',
        'warmup_limit' => 'Set warmup daily limit',
        'warmup_increment' => 'Set warmup daily increase',
        'warmup_reply_rate' => 'Set warmup reply rate %',
        'pause' => 'Pause mailboxes',
        'resume' => 'Resume mailboxes',
        'add_tag' => 'Add tag',
        'remove_tag' => 'Remove tag',
    ];

    private const ERROR_STATUSES = ['connection_error', 'sending_error', 'soft_bounce_error'];

    public int $throttleMicros = 150000;

    public function __construct(private AuditLogger $audit) {}

    // ------------------------------------------------------------------
    // Overview (from the latest hourly sync — no live calls)
    // ------------------------------------------------------------------

    public function summary(): array
    {
        $workspaces = Workspace::where('counts_toward_totals', true)->orderBy('name')->get()->keyBy('id');
        $records = ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->whereIn('workspace_id', $workspaces->keys())->get(['workspace_id', 'payload', 'imported_at']);

        $out = [];
        foreach ($workspaces as $ws) {
            $out[$ws->id] = [
                'workspace_id' => $ws->id, 'name' => $ws->name, 'code' => $ws->code,
                'last_synced_at' => $ws->last_synced_at,
                'totals' => $this->emptyStats(), 'tags' => [],
            ];
        }

        foreach ($records as $r) {
            $p = $r->payload ?? [];
            $w = &$out[$r->workspace_id];
            $this->add($w['totals'], $p);
            $tags = $p['tags'] ?? [];
            if (! $tags) {
                $tags = [['id' => null, 'label' => '(no tag)']];
            }
            foreach ($tags as $t) {
                $key = $t['id'] ?? 'none';
                $w['tags'][$key] ??= ['tag_id' => $t['id'] ?? null, 'label' => $t['label'] ?? '(unnamed)'] + $this->emptyStats();
                $this->add($w['tags'][$key], $p);
            }
            unset($w);
        }

        return array_values(array_map(function ($w) {
            $w['totals'] = $this->finish($w['totals']);
            $tags = array_map(fn ($t) => $this->finish($t), array_values($w['tags']));
            usort($tags, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
            $w['tags'] = $tags;

            return $w;
        }, $out));
    }

    private function emptyStats(): array
    {
        return ['total' => 0, 'active' => 0, 'paused' => 0, 'error' => 0, 'other' => 0,
            'warmup_on' => 0, 'score_sum' => 0, 'score_n' => 0, 'daily_capacity' => 0];
    }

    private function add(array &$s, array $p): void
    {
        $s['total']++;
        $status = $p['status'] ?? null;
        match (true) {
            $status === 'active' => $s['active']++,
            $status === 'paused' => $s['paused']++,
            in_array($status, self::ERROR_STATUSES, true) => $s['error']++,
            default => $s['other']++,
        };
        if (($p['warmup_status'] ?? null) === 1) {
            $s['warmup_on']++;
        }
        if (is_numeric($p['stat_warmup_score'] ?? null)) {
            $s['score_sum'] += (float) $p['stat_warmup_score'];
            $s['score_n']++;
        }
        if ($status === 'active' && is_numeric($p['daily_limit'] ?? null)) {
            $s['daily_capacity'] += (int) $p['daily_limit'];
        }
    }

    private function finish(array $s): array
    {
        $s['avg_warmup_score'] = $s['score_n'] ? round($s['score_sum'] / $s['score_n'], 1) : null;
        $s['error_pct'] = $s['total'] ? round($s['error'] / $s['total'] * 100, 1) : 0;
        $s['health'] = match (true) {
            $s['total'] === 0 => 'empty',
            $s['error_pct'] >= 10 => 'bad',
            $s['error'] > 0 || $s['paused'] > 0 || ($s['warmup_on'] < $s['total']) || ($s['avg_warmup_score'] !== null && $s['avg_warmup_score'] < 90) => 'warn',
            default => 'ok',
        };
        unset($s['score_sum'], $s['score_n']);

        return $s;
    }

    // ------------------------------------------------------------------
    // Bulk edits
    // ------------------------------------------------------------------

    /**
     * Resolve the target mailboxes: an explicit list, or everything matching a
     * live Instantly filter (tag / status / search) across all pages.
     *
     * @return array<int,string>
     */
    public function resolveEmails(Workspace $workspace, array $data): array
    {
        if (($data['scope'] ?? 'emails') === 'emails') {
            return array_values(array_unique($data['emails'] ?? []));
        }

        $f = $data['filter'] ?? [];
        $client = $this->client($workspace);
        $emails = [];
        $cursor = null;
        $pages = 0;
        do {
            $q = ['limit' => 100];
            if (! empty($f['tag_ids'])) {
                $q['tag_ids'] = (string) $f['tag_ids'];
            }
            if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== null) {
                $q['status'] = (int) $f['status'];
            }
            if (! empty($f['search'])) {
                $q['search'] = (string) $f['search'];
            }
            if ($cursor) {
                $q['starting_after'] = $cursor;
            }
            $page = $client->listAccountsPage($q);
            foreach ($page['items'] ?? [] as $a) {
                if (! empty($a['email'])) {
                    $emails[] = $a['email'];
                }
            }
            $cursor = $page['next_starting_after'] ?? null;
            $pages++;
        } while ($cursor && $pages < 50);

        return array_values(array_unique($emails));
    }

    public function queue(Workspace $workspace, string $action, array $params, array $emails, ?int $userId): MailboxOperation
    {
        return MailboxOperation::create([
            'workspace_id' => $workspace->id,
            'action' => $action,
            'params' => $params,
            'emails' => $emails,
            'total' => count($emails),
            'status' => 'queued',
            'user_id' => $userId,
        ]);
    }

    public function run(MailboxOperation $op): MailboxOperation
    {
        @set_time_limit(0);
        $op->update(['status' => 'running', 'started_at' => now()]);
        $workspace = $op->workspace;
        $emails = $op->emails ?? [];
        $v = $op->params['value'] ?? null;

        try {
            $client = $this->client($workspace);
            switch ($op->action) {
                case 'warmup_on':
                case 'warmup_off':
                    foreach (array_chunk($emails, 100) as $chunk) {
                        $this->attempt($op, count($chunk), fn () => $client->setWarmup($chunk, $op->action === 'warmup_on'), 'warmup batch');
                    }
                    $this->localUpdate($workspace, $emails, ['warmup_status' => $op->action === 'warmup_on' ? 1 : 0]);
                    break;

                case 'add_tag':
                case 'remove_tag':
                    $refs = $this->refs($workspace, $emails);
                    foreach (array_chunk($refs, 100) as $chunk) {
                        $this->attempt($op, count($chunk), fn () => $client->toggleAccountTags([(string) $v], $chunk, $op->action === 'add_tag'), 'tag batch');
                    }
                    break;

                case 'pause':
                case 'resume':
                    foreach ($emails as $email) {
                        $this->attempt($op, 1, fn () => $op->action === 'pause' ? $client->pauseAccount($email) : $client->resumeAccount($email), $email);
                        usleep($this->throttleMicros);
                    }
                    $this->localUpdate($workspace, $emails, ['status' => $op->action === 'pause' ? 'paused' : 'active']);
                    break;

                default:
                    $body = $this->patchBody($op->action, $v);
                    foreach ($emails as $email) {
                        $this->attempt($op, 1, fn () => $client->patchAccount($email, $body), $email);
                        usleep($this->throttleMicros);
                    }
                    if ($op->action === 'daily_limit') {
                        $this->localUpdate($workspace, $emails, ['daily_limit' => (int) $v]);
                    }
            }
            $op->status = $op->failed > 0 ? 'completed_with_errors' : 'completed';
            $op->note("Done: {$op->done} updated".($op->failed ? ", {$op->failed} failed" : '').'.');
        } catch (Throwable $e) {
            $op->status = 'failed';
            $op->error = $e->getMessage();
            $op->note('FAILED: '.$e->getMessage());
        }

        $op->finished_at = now();
        $op->save();

        $this->audit->log('instantly.bulk.'.$op->action, null, [
            'operation' => $op->id, 'workspace' => $workspace->code, 'value' => $v,
            'mailboxes' => count($emails), 'done' => $op->done, 'failed' => $op->failed,
        ], $op->user, 'mailbox_operation', $op->id);

        return $op;
    }

    /** PATCH body for a settings action (warmup fields nest under "warmup"). */
    public function patchBody(string $action, mixed $v): array
    {
        return match ($action) {
            'daily_limit' => ['daily_limit' => (int) $v],
            'sending_gap' => ['sending_gap' => (int) $v],
            'enable_slow_ramp' => ['enable_slow_ramp' => filter_var($v, FILTER_VALIDATE_BOOLEAN)],
            'warmup_limit' => ['warmup' => ['limit' => (int) $v]],
            'warmup_increment' => ['warmup' => ['increment' => (string) $v]],
            'warmup_reply_rate' => ['warmup' => ['reply_rate' => (float) $v]],
            default => throw new RuntimeException("Unsupported action {$action}"),
        };
    }

    private function attempt(MailboxOperation $op, int $count, callable $fn, string $what): void
    {
        try {
            try {
                $fn();
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), '429')) {
                    throw $e;
                }
                sleep(10);
                $fn();
            }
            $op->done += $count;
        } catch (Throwable $e) {
            $op->failed += $count;
            if ($op->failed <= 10) {
                $op->note("Failed ({$what}): ".$e->getMessage());
            }
        }
        // Persist progress every so often so the UI can show it.
        if (($op->done + $op->failed) % 20 === 0 || $count > 1) {
            $op->save();
        }
    }

    /** Keep the synced snapshot in step so the overview reflects the change immediately. */
    private function localUpdate(Workspace $workspace, array $emails, array $fields): void
    {
        ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->where('workspace_id', $workspace->id)->whereIn('external_record_id', $emails)->get()
            ->each(fn ($r) => $r->update(['payload' => array_merge($r->payload ?? [], $fields)]));
    }

    /** @return array<int,string> Instantly resource ids (account id when known, else email) */
    private function refs(Workspace $workspace, array $emails): array
    {
        $ids = ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->where('workspace_id', $workspace->id)->whereIn('external_record_id', $emails)->get(['external_record_id', 'payload'])
            ->mapWithKeys(fn ($r) => [$r->external_record_id => $r->payload['id'] ?? null]);

        return array_map(fn ($e) => (string) ($ids[$e] ?? $e), $emails);
    }

    private function client(?Workspace $workspace): InstantlyClient
    {
        $key = $workspace ? IntegrationConnection::where('provider', 'instantly')
            ->where('workspace_id', $workspace->id)->first()?->apiKey() : null;
        if (! $key) {
            throw new RuntimeException('No Instantly API key is configured for this workspace.');
        }

        return new InstantlyClient($key);
    }
}
