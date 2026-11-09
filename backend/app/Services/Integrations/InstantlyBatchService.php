<?php

namespace App\Services\Integrations;

use App\Models\Batch;
use App\Models\BatchLifecycleEvent;
use App\Models\BatchOperation;
use App\Models\Campaign;
use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Batch-level writes to Instantly. Everything here is operator-initiated,
 * previewed first, run in the background, tracked as a BatchOperation and
 * audit-logged. Nothing is triggered automatically by detection rules.
 *
 *   tag_sync — create the batch's tag (label = batch ID) and tag all its mailboxes
 *   assign   — move chosen mailboxes into a batch (app + Instantly tags)
 *   stage    — apply a lifecycle stage's Instantly settings to every mailbox
 *   rotate   — swap a batch's mailboxes out of campaigns for a replacement batch
 */
class InstantlyBatchService
{
    /** What each stage means in Instantly. */
    public const STAGE_ACTIONS = [
        'warmup' => ['warmup_on', 'remove_from_campaigns'],
        'ramp' => ['warmup_on', 'set_ramp_limit', 'resume'],
        'active' => ['warmup_on', 'set_active_limit', 'resume'],
        'resting' => ['remove_from_campaigns', 'warmup_on'],
        'retired' => ['remove_from_campaigns', 'pause'],
    ];

    private const ACTION_LABELS = [
        'warmup_on' => 'Turn warmup ON for every mailbox',
        'remove_from_campaigns' => 'Remove the mailboxes from all campaigns (sending accounts)',
        'set_ramp_limit' => 'Set daily sending limit to the week-1 ramp limit',
        'set_active_limit' => "Set daily sending limit to the batch's active daily limit",
        'resume' => 'Resume any paused mailboxes',
        'pause' => 'Pause every mailbox',
    ];

    /** Delay between per-mailbox writes to stay well inside Instantly's rate limit. */
    public int $throttleMicros = 150000;

    /** @var array<string, array<string, array<int,string>>> */
    private array $tagMembersCache = [];

    public function __construct(
        private AccountMappingService $mapping,
        private AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Previews (read-only)
    // ------------------------------------------------------------------

    public function previewStage(Batch $batch, string $phase): array
    {
        $accounts = $this->accounts($batch);
        $actions = self::STAGE_ACTIONS[$phase] ?? [];
        $limit = $this->limitFor($batch, $phase);

        $lines = array_map(fn ($a) => self::ACTION_LABELS[$a].(
            in_array($a, ['set_ramp_limit', 'set_active_limit'], true) ? ' ('.($limit ?? 'not set — skipped').')' : ''
        ), $actions);

        $campaigns = in_array('remove_from_campaigns', $actions, true)
            ? $this->campaignsUsing($batch->workspace, array_keys($accounts))
            : [];

        return [
            'batch_id' => $batch->batch_id,
            'phase' => $phase,
            'accounts' => count($accounts),
            'actions' => $lines,
            'campaigns' => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'accounts_in_campaign' => $c['overlap'], 'selected_by' => $c['by_tag'] ? 'tag' : 'list'], $campaigns),
            'warnings' => $this->warnings($batch, $accounts, $phase, $limit),
        ];
    }

    public function previewRotation(Batch $from, Batch $to): array
    {
        $this->assertRotatable($from, $to);
        $fromAccounts = $this->accounts($from);
        $toAccounts = $this->accounts($to);
        $campaigns = $this->campaignsUsing($from->workspace, array_keys($fromAccounts));

        return [
            'from' => $from->batch_id,
            'to' => $to->batch_id,
            'from_accounts' => count($fromAccounts),
            'to_accounts' => count($toAccounts),
            'campaigns' => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'accounts_in_campaign' => $c['overlap'], 'selected_by' => $c['by_tag'] ? 'tag' : 'list'], $campaigns),
            'actions' => [
                "In each campaign below, replace {$from->batch_id}'s mailboxes with {$to->batch_id}'s ".count($toAccounts).' mailboxes',
                "{$to->batch_id}: warmup ON, daily limit ".($to->active_daily_limit ?? 'unchanged').', resume paused, phase -> Active',
                "{$from->batch_id}: warmup ON (recovery), phase -> Resting",
            ],
            'warnings' => array_values(array_filter([
                count($toAccounts) === 0 ? "{$to->batch_id} has no mailboxes yet — nothing would send." : null,
                count($campaigns) === 0 ? "{$from->batch_id} is not in any campaign — nothing to swap." : null,
                $to->phase === 'warmup' || $to->phase === 'setup' ? "{$to->batch_id} is still in {$to->phase}; confirm its warmup is complete." : null,
            ])),
        ];
    }

    // ------------------------------------------------------------------
    // Queueing + execution
    // ------------------------------------------------------------------

    public function queue(Batch $batch, string $type, array $params, ?int $userId): BatchOperation
    {
        return BatchOperation::create([
            'batch_id' => $batch->id,
            'workspace_id' => $batch->workspace_id,
            'type' => $type,
            'params' => $params,
            'status' => 'queued',
            'user_id' => $userId,
        ]);
    }

    public function run(BatchOperation $op): BatchOperation
    {
        @set_time_limit(0);
        $op->update(['status' => 'running', 'started_at' => now()]);

        try {
            $batch = $op->batch()->with('workspace')->firstOrFail();
            match ($op->type) {
                'tag_sync' => $this->runTagSync($op, $batch),
                'assign' => $this->runAssign($op, $batch),
                'stage' => $this->runStage($op, $batch, (string) $op->params['phase']),
                'rotate' => $this->runRotate($op, $batch, Batch::findOrFail($op->params['to_batch_id'])),
                default => throw new RuntimeException("Unknown operation {$op->type}"),
            };
            $op->status = $op->failed_steps > 0 ? 'completed_with_errors' : 'completed';
        } catch (Throwable $e) {
            $op->status = 'failed';
            $op->error = $e->getMessage();
            $op->note('FAILED: '.$e->getMessage());
        }

        $op->finished_at = now();
        $op->save();

        $this->audit->log('instantly.batch_operation.'.$op->status, $op->batch, [
            'operation' => $op->id, 'type' => $op->type, 'params' => $op->params,
            'done' => $op->done_steps, 'failed' => $op->failed_steps,
        ], $op->user);

        return $op;
    }

    private function runTagSync(BatchOperation $op, Batch $batch): void
    {
        $client = $this->client($batch->workspace);
        $accounts = $this->accounts($batch);
        $op->total_steps = count($accounts) + 1;
        $op->save();

        $tagId = $client->ensureCustomTag($batch->batch_id, 'RevCadence batch');
        $this->step($op, "Tag \"{$batch->batch_id}\" ready in Instantly.");

        $refs = $this->refsFor($batch->workspace, array_keys($accounts));
        $this->chunked($op, $refs, 100, function (array $chunk) use ($client, $tagId) {
            $client->toggleAccountTags([$tagId], array_values($chunk), true);
        }, 'Tagged');
    }

    private function runAssign(BatchOperation $op, Batch $batch): void
    {
        $emails = array_values(array_unique($op->params['emails'] ?? []));
        $op->total_steps = count($emails) + 1;
        $op->save();

        // App side first: the mailbox belongs to this batch from now on (sticky),
        // even if the Instantly tag write below fails.
        $previous = $this->mapping->assignToBatch($batch, $emails);
        $this->step($op, 'Moved '.count($emails)." mailbox(es) to {$batch->batch_id} in the app.");

        $client = $this->client($batch->workspace);
        $refs = $this->refsFor($batch->workspace, $emails);
        $tagId = $client->ensureCustomTag($batch->batch_id, 'RevCadence batch');

        // Remove the old batch tags so a mailbox only ever carries one batch tag.
        foreach (Batch::whereIn('id', array_unique(array_values($previous)))->get() as $old) {
            $moved = array_keys(array_filter($previous, fn ($bid) => $bid === $old->id));
            try {
                $oldTag = $client->ensureCustomTag($old->batch_id, 'RevCadence batch');
                $client->toggleAccountTags([$oldTag], array_values(array_intersect_key($refs, array_flip($moved))), false);
                $op->note("Removed tag {$old->batch_id} from ".count($moved).' mailbox(es).');
            } catch (Throwable $e) {
                $op->note("Could not remove tag {$old->batch_id}: ".$e->getMessage());
            }
        }

        $this->chunked($op, $refs, 100, function (array $chunk) use ($client, $tagId) {
            $client->toggleAccountTags([$tagId], array_values($chunk), true);
        }, 'Tagged');
    }

    private function runStage(BatchOperation $op, Batch $batch, string $phase): void
    {
        $actions = self::STAGE_ACTIONS[$phase] ?? [];
        $client = $this->client($batch->workspace);
        $accounts = $this->accounts($batch);
        $emails = array_keys($accounts);
        $op->total_steps = count($actions);
        $op->save();

        foreach ($actions as $action) {
            match ($action) {
                'warmup_on' => $this->warmupOn($op, $client, $emails),
                'remove_from_campaigns' => $this->removeFromCampaigns($op, $client, $batch->workspace, $emails),
                'set_ramp_limit', 'set_active_limit' => $this->setLimit($op, $client, $batch, $this->limitFor($batch, $phase)),
                'resume' => $this->perAccount($op, $client, $batch, fn ($e, $p) => ($p['status'] ?? '') === 'paused', fn ($e) => $client->resumeAccount($e), 'Resumed'),
                'pause' => $this->perAccount($op, $client, $batch, fn ($e, $p) => ($p['status'] ?? '') !== 'paused', fn ($e) => $client->pauseAccount($e), 'Paused'),
            };
            $op->done_steps++;
            $op->save();
        }

        $this->transitionInApp($batch, $phase, $op->user_id, "Applied to Instantly (operation {$op->id}).");
    }

    private function runRotate(BatchOperation $op, Batch $from, Batch $to): void
    {
        $this->assertRotatable($from, $to);
        $client = $this->client($from->workspace);
        $fromEmails = array_keys($this->accounts($from));
        $toEmails = array_keys($this->accounts($to));
        $campaigns = $this->campaignsUsing($from->workspace, $fromEmails);
        $op->total_steps = count($campaigns) + 3;
        $op->save();

        foreach ($campaigns as $c) {
            try {
                $client->setCampaignSending($c['id'], $this->reselect($from->workspace, $c, $fromEmails, $toEmails));
                $this->step($op, "Campaign \"{$c['name']}\": swapped {$c['overlap']} {$from->batch_id} mailbox(es) for ".count($toEmails)." {$to->batch_id} mailbox(es).");
            } catch (Throwable $e) {
                $this->fail($op, "Campaign \"{$c['name']}\": ".$e->getMessage());
            }
        }

        // Replacement goes live.
        $this->warmupOn($op, $client, $toEmails);
        $this->setLimit($op, $client, $to, $to->active_daily_limit);
        $this->perAccount($op, $client, $to, fn ($e, $p) => ($p['status'] ?? '') === 'paused', fn ($e) => $client->resumeAccount($e), 'Resumed');
        $op->done_steps++;
        if (! $to->active_start) {
            $to->update(['active_start' => now()->toDateString()]);
        }
        $this->transitionInApp($to, 'active', $op->user_id, "Rotated in to replace {$from->batch_id}.");

        // Old batch rests with warmup on.
        $this->warmupOn($op, $client, $fromEmails);
        $op->done_steps++;
        $this->transitionInApp($from, 'resting', $op->user_id, "Rotated out; replaced by {$to->batch_id}.");
        $op->done_steps++;
        $op->save();
    }

    // ------------------------------------------------------------------
    // Building blocks
    // ------------------------------------------------------------------

    private function warmupOn(BatchOperation $op, InstantlyClient $client, array $emails): void
    {
        foreach (array_chunk($emails, 100) as $chunk) {
            try {
                $client->setWarmup($chunk, true);
            } catch (Throwable $e) {
                $this->fail($op, 'Warmup enable failed for '.count($chunk).' mailbox(es): '.$e->getMessage());

                return;
            }
        }
        $op->note('Warmup ON queued in Instantly for '.count($emails).' mailbox(es).');
        $op->save();
    }

    private function removeFromCampaigns(BatchOperation $op, InstantlyClient $client, Workspace $workspace, array $emails): void
    {
        $campaigns = $this->campaignsUsing($workspace, $emails);
        if (! $campaigns) {
            $op->note('Not in any campaign — nothing to remove.');

            return;
        }
        foreach ($campaigns as $c) {
            try {
                $client->setCampaignSending($c['id'], $this->reselect($workspace, $c, $emails));
                $op->note("Removed {$c['overlap']} mailbox(es) from campaign \"{$c['name']}\"".($c['by_tag'] ? ' (tag selection replaced by an explicit list)' : '').'.');
            } catch (Throwable $e) {
                $this->fail($op, "Campaign \"{$c['name']}\": ".$e->getMessage());
            }
        }
        $op->save();
    }

    private function setLimit(BatchOperation $op, InstantlyClient $client, Batch $batch, ?int $limit): void
    {
        if (! $limit) {
            $op->note('Daily limit not set on the batch — limit left unchanged.');

            return;
        }
        $this->perAccount($op, $client, $batch,
            fn ($e, $p) => (int) ($p['daily_limit'] ?? -1) !== $limit,
            fn ($e) => $client->patchAccount($e, ['daily_limit' => $limit]),
            "Daily limit -> {$limit}");
    }

    /** Apply a write to each mailbox that needs it, throttled, counting failures. */
    private function perAccount(BatchOperation $op, InstantlyClient $client, Batch $batch, callable $needs, callable $write, string $label): void
    {
        $done = $failed = 0;
        foreach ($this->accounts($batch) as $email => $payload) {
            if (! $needs($email, $payload)) {
                continue;
            }
            try {
                $this->withRetry(fn () => $write($email));
                $done++;
            } catch (Throwable $e) {
                $failed++;
                if ($failed <= 5) {
                    $op->note("{$label} failed for {$email}: ".$e->getMessage());
                }
            }
            usleep($this->throttleMicros);
        }
        $op->failed_steps += $failed;
        $op->note("{$label}: {$done} mailbox(es) updated".($failed ? ", {$failed} failed" : '').'.');
        $op->save();
    }

    private function chunked(BatchOperation $op, array $items, int $size, callable $fn, string $label): void
    {
        foreach (array_chunk($items, $size, true) as $chunk) {
            try {
                $this->withRetry(fn () => $fn($chunk));
                $op->done_steps += count($chunk);
                $op->note("{$label} ".count($chunk).' mailbox(es).');
            } catch (Throwable $e) {
                $op->failed_steps += count($chunk);
                $op->note("{$label} failed for ".count($chunk).' mailbox(es): '.$e->getMessage());
            }
            $op->save();
        }
    }

    private function withRetry(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            if (! str_contains($e->getMessage(), '429')) {
                throw $e;
            }
            sleep(10);

            return $fn();
        }
    }

    private function step(BatchOperation $op, string $line): void
    {
        $op->done_steps++;
        $op->note($line);
        $op->save();
    }

    private function fail(BatchOperation $op, string $line): void
    {
        $op->failed_steps++;
        $op->note('ERROR '.$line);
        $op->save();
    }

    private function transitionInApp(Batch $batch, string $phase, ?int $userId, string $note): void
    {
        if ($batch->phase === $phase) {
            return;
        }
        DB::transaction(function () use ($batch, $phase, $userId, $note) {
            $from = $batch->phase;
            $batch->update(['phase' => $phase]);
            BatchLifecycleEvent::create([
                'batch_id' => $batch->id,
                'phase' => $phase,
                'from_phase' => $from,
                'entered_at' => now(),
                'changed_by_user_id' => $userId,
                'note' => $note,
            ]);
        });
    }

    /**
     * Mailboxes in the batch, keyed by email, with their latest synced payload.
     *
     * @return array<string,array>
     */
    private function accounts(Batch $batch): array
    {
        return ImportedRecord::where('source', 'instantly')
            ->where('entity_type', 'account')
            ->where('batch_id', $batch->id)
            ->get(['external_record_id', 'payload'])
            ->mapWithKeys(fn ($r) => [$r->external_record_id => $r->payload ?? []])
            ->all();
    }

    /**
     * Instantly resource ids for tag toggling (account id when known, else email).
     *
     * @return array<string,string> email => resource id
     */
    private function refsFor(Workspace $workspace, array $emails): array
    {
        $payloads = ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->where('workspace_id', $workspace->id)->whereIn('external_record_id', $emails)
            ->get(['external_record_id', 'payload'])->mapWithKeys(fn ($r) => [$r->external_record_id => $r->payload ?? []]);

        $refs = [];
        foreach ($emails as $email) {
            $refs[$email] = (string) ($payloads[$email]['id'] ?? $email);
        }

        return $refs;
    }

    /**
     * Campaigns in the workspace that send from any of $emails — either listed
     * directly (email_list) or selected through a tag (email_tag_list).
     */
    private function campaignsUsing(Workspace $workspace, array $emails): array
    {
        if (! $emails) {
            return [];
        }
        $client = $this->client($workspace);
        $members = $this->tagMembers($workspace);
        $set = array_flip(array_map('strtolower', $emails));
        $out = [];
        foreach (Campaign::where('workspace_id', $workspace->id)->whereNotNull('external_id')->get() as $c) {
            $full = $client->getCampaign($c->external_id);
            $list = array_values(array_filter($full['email_list'] ?? [], 'is_string'));
            $tags = array_values(array_filter($full['email_tag_list'] ?? [], 'is_string'));
            $senders = $list;
            foreach ($tags as $t) {
                $senders = array_merge($senders, $members[$t] ?? []);
            }
            $senders = array_values(array_unique(array_map('strtolower', $senders)));
            $overlap = count(array_filter($senders, fn ($e) => isset($set[$e])));
            if ($overlap > 0) {
                $out[] = [
                    'id' => $c->external_id, 'name' => $full['name'] ?? $c->name,
                    'email_list' => $list, 'email_tag_list' => $tags, 'overlap' => $overlap,
                    'by_tag' => count(array_filter($tags, fn ($t) => array_intersect(array_map('strtolower', $members[$t] ?? []), array_keys($set)))) > 0,
                ];
            }
        }

        return $out;
    }

    /**
     * New sending selection for a campaign after removing $remove and adding $add.
     * A tag that would still pull in removed mailboxes is dropped, and the tag's
     * OTHER mailboxes are listed explicitly instead — so every mailbox that was
     * sending and isn't being removed keeps sending.
     *
     * @return array{email_list: array, email_tag_list: array}
     */
    private function reselect(Workspace $workspace, array $campaign, array $remove, array $add = []): array
    {
        $members = $this->tagMembers($workspace);
        $rm = array_flip(array_map('strtolower', $remove));
        $list = array_values(array_filter($campaign['email_list'], fn ($e) => ! isset($rm[strtolower($e)])));
        $tags = [];
        foreach ($campaign['email_tag_list'] as $t) {
            $m = $members[$t] ?? [];
            $hit = array_filter($m, fn ($e) => isset($rm[strtolower($e)]));
            if ($hit) {
                $list = array_merge($list, array_filter($m, fn ($e) => ! isset($rm[strtolower($e)])));
            } else {
                $tags[] = $t;
            }
        }

        return [
            'email_list' => array_values(array_unique(array_merge($list, $add))),
            'email_tag_list' => array_values(array_unique($tags)),
        ];
    }

    /** @return array<string, array<int,string>> Instantly tag id => mailbox emails in the workspace */
    private function tagMembers(Workspace $workspace): array
    {
        if (isset($this->tagMembersCache[$workspace->id])) {
            return $this->tagMembersCache[$workspace->id];
        }
        $map = [];
        ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->where('workspace_id', $workspace->id)->get(['external_record_id', 'payload'])
            ->each(function ($r) use (&$map) {
                foreach ($r->payload['tags'] ?? [] as $t) {
                    if (! empty($t['id'])) {
                        $map[$t['id']][] = $r->external_record_id;
                    }
                }
            });

        return $this->tagMembersCache[$workspace->id] = $map;
    }

    private function limitFor(Batch $batch, string $phase): ?int
    {
        return match ($phase) {
            'ramp' => (int) ($batch->rampSchedules()->orderBy('week_no')->value('daily_limit_per_account') ?? 0) ?: null,
            'active' => $batch->active_daily_limit,
            default => null,
        };
    }

    private function warnings(Batch $batch, array $accounts, string $phase, ?int $limit): array
    {
        $w = [];
        if (! $accounts) {
            $w[] = 'No mailboxes are mapped to this batch yet — sync or assign mailboxes first.';
        }
        if (in_array($phase, ['ramp', 'active'], true) && ! $limit) {
            $w[] = 'No daily limit is set for this stage, so sending limits will not be changed.';
        }
        if ($phase === 'retired') {
            $w[] = 'Retiring pauses every mailbox in the batch.';
        }

        return $w;
    }

    private function assertRotatable(Batch $from, Batch $to): void
    {
        if ($from->id === $to->id || $from->workspace_id !== $to->workspace_id) {
            throw new RuntimeException('Rotation must be between two different batches in the same workspace.');
        }
    }

    private function client(?Workspace $workspace): InstantlyClient
    {
        $key = $workspace ? IntegrationConnection::where('provider', 'instantly')
            ->where('workspace_id', $workspace->id)->first()?->apiKey() : null;
        if (! $key) {
            throw new RuntimeException('No Instantly API key is configured for this workspace. Connect it under Integrations first.');
        }

        return new InstantlyClient($key);
    }
}
