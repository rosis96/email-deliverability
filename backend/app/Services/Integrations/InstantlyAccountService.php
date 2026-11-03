<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\Workspace;
use App\Services\AuditLogger;
use RuntimeException;

/**
 * The gated, audit-logged write/read layer behind the Email Accounts view.
 *
 * Reads live per-workspace from Instantly (accounts + tags). Writes go straight
 * to Instantly via InstantlyClient and are recorded in the audit log. Role
 * enforcement happens at the route (role:super_admin,deliverability_manager,
 * operator); this service assumes the caller is already authorised.
 */
class InstantlyAccountService
{
    public function __construct(private AuditLogger $audit) {}

    private const PROVIDER_LABELS = [
        1 => 'IMAP/SMTP',
        2 => 'Google',
        3 => 'Microsoft',
        4 => 'Office 365',
        8 => 'IMAP/SMTP',
        11 => 'Maildoso',
    ];

    private const STATUS = [
        1 => ['Active', 'ok'],
        2 => ['Paused', 'neutral'],
        3 => ['Maintenance', 'info'],
        -1 => ['Connection error', 'bad'],
        -2 => ['Soft bounce error', 'bad'],
        -3 => ['Sending error', 'bad'],
    ];

    private const WARMUP_STATUS = [
        0 => ['Paused', 'neutral'],
        1 => ['Active', 'ok'],
        -1 => ['Banned', 'bad'],
        -2 => ['Spam folder', 'warn'],
        -3 => ['Suspended', 'bad'],
    ];

    /** Resolve this workspace's Instantly client, or fail with a clear message. */
    private function client(Workspace $workspace): InstantlyClient
    {
        $conn = IntegrationConnection::where('provider', 'instantly')
            ->where('workspace_id', $workspace->id)
            ->first();

        $key = $conn?->apiKey();
        if (! $key) {
            throw new RuntimeException('No Instantly API key is configured for this workspace. Connect it under Integrations first.');
        }

        return new InstantlyClient($key);
    }

    /**
     * List accounts for a workspace with optional tag/status/search filters.
     *
     * @param  array<string,mixed>  $filters  tag_ids, status, search, starting_after, limit
     * @return array{items:array<int,array>, next:?string}
     */
    public function listAccounts(Workspace $workspace, array $filters): array
    {
        $query = [
            'limit' => min(100, max(1, (int) ($filters['limit'] ?? 100))),
            'include_tags' => 'true',
        ];
        if (! empty($filters['search'])) {
            $query['search'] = (string) $filters['search'];
        }
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query['status'] = (int) $filters['status'];
        }
        if (! empty($filters['tag_ids'])) {
            $query['tag_ids'] = is_array($filters['tag_ids'])
                ? implode(',', $filters['tag_ids'])
                : (string) $filters['tag_ids'];
        }
        if (! empty($filters['starting_after'])) {
            $query['starting_after'] = (string) $filters['starting_after'];
        }

        $page = $this->client($workspace)->listAccountsPage($query);

        $items = array_map([$this, 'transformRow'], $page['items'] ?? []);

        return ['items' => $items, 'next' => $page['next_starting_after'] ?? null];
    }

    /** Custom tags for the filter dropdown. @return array<int,array{id:string,label:string}> */
    public function tags(Workspace $workspace): array
    {
        return array_map(
            fn (array $t) => ['id' => $t['id'] ?? null, 'label' => $t['label'] ?? '(unnamed)'],
            $this->client($workspace)->listCustomTags(),
        );
    }

    /** Full account detail for the edit panel (raw fields + computed labels). */
    public function detail(Workspace $workspace, string $email): array
    {
        $acct = $this->client($workspace)->getAccount($email);

        return $this->transformDetail($acct);
    }

    /**
     * Apply a settings/warmup update, write it to Instantly, log it, and return
     * the refreshed account.
     *
     * @param  array<string,mixed>  $body  pre-built PATCH body (writable fields only)
     */
    public function update(Workspace $workspace, string $email, array $body): array
    {
        $updated = $this->client($workspace)->patchAccount($email, $body);

        $this->audit->log('instantly.account.updated', null, [
            'workspace' => $workspace->code ?? $workspace->name,
            'email' => $email,
            'fields' => array_keys($body),
            'values' => $body,
        ]);

        return $this->transformDetail($updated);
    }

    public function pause(Workspace $workspace, string $email): array
    {
        $acct = $this->client($workspace)->pauseAccount($email);
        $this->audit->log('instantly.account.paused', null, [
            'workspace' => $workspace->code ?? $workspace->name,
            'email' => $email,
        ]);

        return $this->transformDetail($acct);
    }

    public function resume(Workspace $workspace, string $email): array
    {
        $acct = $this->client($workspace)->resumeAccount($email);
        $this->audit->log('instantly.account.resumed', null, [
            'workspace' => $workspace->code ?? $workspace->name,
            'email' => $email,
        ]);

        return $this->transformDetail($acct);
    }

    /** Enable/disable warmup (bulk+async upstream). Returns the BackgroundJob. */
    public function setWarmup(Workspace $workspace, string $email, bool $enable): array
    {
        $job = $this->client($workspace)->setWarmup([$email], $enable);
        $this->audit->log($enable ? 'instantly.account.warmup_enabled' : 'instantly.account.warmup_disabled', null, [
            'workspace' => $workspace->code ?? $workspace->name,
            'email' => $email,
        ]);

        return [
            'queued' => true,
            'enable' => $enable,
            'job_id' => $job['id'] ?? null,
            'job_status' => $job['status'] ?? null,
        ];
    }

    // --- transforms -------------------------------------------------------

    /** Compact row for the list table. */
    private function transformRow(array $a): array
    {
        [$sLabel, $sTone] = self::STATUS[$a['status'] ?? null] ?? ['Unknown', 'neutral'];
        $code = $a['provider_code'] ?? null;

        return [
            'email' => $a['email'] ?? null,
            'first_name' => $a['first_name'] ?? null,
            'last_name' => $a['last_name'] ?? null,
            'provider_code' => $code,
            'type_label' => self::PROVIDER_LABELS[$code] ?? ($code !== null ? 'Provider '.$code : '—'),
            'status' => $a['status'] ?? null,
            'status_label' => $sLabel,
            'status_tone' => $sTone,
            'warmup_status' => $a['warmup_status'] ?? null,
            'daily_limit' => $a['daily_limit'] ?? null,
            'warmup_score' => $a['stat_warmup_score'] ?? null,
            'tags' => array_map(
                fn (array $t) => ['id' => $t['id'] ?? null, 'label' => $t['label'] ?? ''],
                $a['tags'] ?? [],
            ),
        ];
    }

    /** Full detail incl. the editable settings/warmup fields. */
    private function transformDetail(array $a): array
    {
        [$sLabel, $sTone] = self::STATUS[$a['status'] ?? null] ?? ['Unknown', 'neutral'];
        [$wLabel, $wTone] = self::WARMUP_STATUS[$a['warmup_status'] ?? null] ?? ['Unknown', 'neutral'];
        $warmup = $a['warmup'] ?? [];
        $code = $a['provider_code'] ?? null;

        return [
            'email' => $a['email'] ?? null,
            'first_name' => $a['first_name'] ?? null,
            'last_name' => $a['last_name'] ?? null,
            'provider_code' => $code,
            'type_label' => self::PROVIDER_LABELS[$code] ?? ($code !== null ? 'Provider '.$code : '—'),
            // status (read-only)
            'status' => $a['status'] ?? null,
            'status_label' => $sLabel,
            'status_tone' => $sTone,
            'warmup_status' => $a['warmup_status'] ?? null,
            'warmup_status_label' => $wLabel,
            'warmup_status_tone' => $wTone,
            'warmup_score' => $a['stat_warmup_score'] ?? null,
            'warmup_started_at' => $a['timestamp_warmup_start'] ?? null,
            // editable settings
            'daily_limit' => $a['daily_limit'] ?? null,
            'daily_limit_max' => $a['daily_limit_max'] ?? null,
            'sending_gap' => $a['sending_gap'] ?? null,
            'enable_slow_ramp' => $a['enable_slow_ramp'] ?? null,
            'reply_to' => $a['reply_to'] ?? null,
            'inbox_placement_test_limit' => $a['inbox_placement_test_limit'] ?? null,
            'tracking_domain_name' => $a['tracking_domain_name'] ?? null,
            'tracking_domain_status' => $a['tracking_domain_status'] ?? null,
            // editable warmup
            'warmup_limit' => $warmup['limit'] ?? null,
            'warmup_increment' => $warmup['increment'] ?? null,
            'warmup_reply_rate' => $warmup['reply_rate'] ?? null,
            'warmup_limit_max' => $a['warmup_limit_max'] ?? null,
        ];
    }
}
