<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Instantly.ai v2 API (https://api.instantly.ai).
 *
 * Historically GET-only. As of the Email Accounts management feature it also
 * issues a SMALL, EXPLICIT set of write calls (PATCH account, pause/resume,
 * warmup enable/disable) used by the gated, audit-logged write layer. Every
 * write goes through InstantlyAccountService, never directly from a controller.
 * A v2 API key is scoped to a single workspace, so one client == one workspace.
 */
class InstantlyClient
{
    private string $baseUrl = 'https://api.instantly.ai/api/v2';

    public function __construct(private string $apiKey) {}

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500, throw: false);
    }

    private function get(string $path, array $query = []): array
    {
        // IMPORTANT: Guzzle's `query` option OVERWRITES any query string already
        // in the URL. When the path already carries one (we build `emails=`
        // params manually), we must not pass a query array or it gets wiped.
        $req = $this->http();
        $res = empty($query)
            ? $req->get($this->baseUrl.$path)
            : $req->get($this->baseUrl.$path, $query);

        if ($res->status() === 401) {
            throw new RuntimeException('Instantly: invalid or missing API key (401).');
        }
        if ($res->status() === 403) {
            throw new RuntimeException('Instantly: API key lacks the required scope (403).');
        }
        if ($res->status() === 429) {
            throw new RuntimeException('Instantly: rate limit exceeded (429). Try again shortly.');
        }
        if ($res->failed()) {
            throw new RuntimeException('Instantly API error ('.$res->status().'): '.$res->body());
        }

        return $res->json() ?? [];
    }

    /** Identifies the workspace this key belongs to. Used to verify a connection. */
    public function currentWorkspace(): array
    {
        return $this->get('/workspaces/current');
    }

    /**
     * Iterate every account in the workspace (cursor pagination).
     *
     * @return array<int,array>
     */
    public function allAccounts(int $maxPages = 100): array
    {
        $items = [];
        $cursor = null;
        $pages = 0;

        do {
            // include_tags: batch membership is read from Instantly tags (tag label = batch ID).
            $query = ['limit' => 100, 'include_tags' => 'true'];
            if ($cursor) {
                $query['starting_after'] = $cursor;
            }
            $page = $this->get('/accounts', $query);
            $batch = $page['items'] ?? [];
            $items = array_merge($items, $batch);
            $cursor = $page['next_starting_after'] ?? null;
            $pages++;
        } while ($cursor && ! empty($batch) && $pages < $maxPages);

        return $items;
    }

    /** @return array<int,array> */
    public function allCampaigns(int $maxPages = 100): array
    {
        $items = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['limit' => 100];
            if ($cursor) {
                $query['starting_after'] = $cursor;
            }
            $page = $this->get('/campaigns', $query);
            $batch = $page['items'] ?? [];
            $items = array_merge($items, $batch);
            $cursor = $page['next_starting_after'] ?? null;
            $pages++;
        } while ($cursor && ! empty($batch) && $pages < $maxPages);

        return $items;
    }

    /**
     * Per-account daily analytics (sent/bounced/replies per day). Max 31-day
     * range and at most 200 emails per call, per the API.
     *
     * @param  array<int,string>  $emails
     * @return array<int,array>
     */
    public function accountsDailyAnalytics(array $emails, string $startDate, string $endDate): array
    {
        $out = [];
        // Work queue of email chunks. The per-workspace response-size cap is on
        // ROWS returned (emails x days-with-sending), which varies by chunk, so
        // we adapt: on a 413 we split the chunk and retry.
        $queue = array_chunk($emails, 100);

        while ($queue) {
            $chunk = array_shift($queue);
            try {
                $res = $this->fetchDailyChunk($chunk, $startDate, $endDate);
                if (is_array($res)) {
                    $out = array_merge($out, array_is_list($res) ? $res : [$res]);
                }
                usleep(150_000); // be gentle on rate limits
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), '413') && count($chunk) > 1) {
                    $half = intdiv(count($chunk), 2);
                    $queue[] = array_slice($chunk, 0, $half);
                    $queue[] = array_slice($chunk, $half);

                    continue;
                }
                throw $e;
            }
        }

        return $out;
    }

    /** One analytics request for a set of emails. */
    private function fetchDailyChunk(array $emails, string $startDate, string $endDate): array
    {
        $qs = http_build_query(['start_date' => $startDate, 'end_date' => $endDate]);
        foreach ($emails as $email) {
            $qs .= '&emails='.rawurlencode($email);
        }

        return $this->get('/accounts/analytics/daily?'.$qs);
    }

    // ---------------------------------------------------------------------
    // Read helpers for the Email Accounts management view
    // ---------------------------------------------------------------------

    /**
     * One page of accounts with the raw envelope { items, next_starting_after }.
     * Pass include_tags='true' to get each account's tags[]. Supports search,
     * status, provider_code, tag_ids, limit, starting_after.
     *
     * @param  array<string,mixed>  $query
     */
    public function listAccountsPage(array $query): array
    {
        return $this->get('/accounts', $query);
    }

    /** A single account by email. Note: this endpoint does NOT include tags. */
    public function getAccount(string $email): array
    {
        return $this->get('/accounts/'.rawurlencode($email));
    }

    /** All custom tags in the workspace (id + label) for the tag filter. */
    public function listCustomTags(int $maxPages = 20): array
    {
        $items = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['limit' => 100];
            if ($cursor) {
                $query['starting_after'] = $cursor;
            }
            $page = $this->get('/custom-tags', $query);
            $batch = $page['items'] ?? [];
            $items = array_merge($items, $batch);
            $cursor = $page['next_starting_after'] ?? null;
            $pages++;
        } while ($cursor && ! empty($batch) && $pages < $maxPages);

        return $items;
    }

    // ---------------------------------------------------------------------
    // WRITE calls (gated + audit-logged upstream in InstantlyAccountService)
    // ---------------------------------------------------------------------

    /**
     * Update an account's settings/warmup. Body must be non-empty and contain
     * only writable fields (daily_limit, sending_gap, enable_slow_ramp, reply_to,
     * inbox_placement_test_limit, tracking_domain_name, first_name, last_name,
     * warmup{limit,increment,reply_rate}). Returns the updated account.
     *
     * @param  array<string,mixed>  $body
     */
    public function patchAccount(string $email, array $body): array
    {
        return $this->write('patch', '/accounts/'.rawurlencode($email), $body);
    }

    /** Pause a single account's sending. Returns the updated account. */
    public function pauseAccount(string $email): array
    {
        return $this->write('post', '/accounts/'.rawurlencode($email).'/pause');
    }

    /** Resume a paused account. Returns the updated account. */
    public function resumeAccount(string $email): array
    {
        return $this->write('post', '/accounts/'.rawurlencode($email).'/resume');
    }

    /**
     * Enable/disable warmup. The upstream endpoint is BULK + ASYNC: it accepts a
     * list of emails and returns a BackgroundJob (not the account). We pass a
     * single email. Poll /background-jobs/{id} if progress tracking is needed.
     *
     * @param  array<int,string>  $emails
     */
    public function setWarmup(array $emails, bool $enable): array
    {
        $path = $enable ? '/accounts/warmup/enable' : '/accounts/warmup/disable';

        return $this->write('post', $path, ['emails' => array_values($emails)]);
    }

    // ---------------------------------------------------------------------
    // Batch-level writes: tags + campaign sending accounts
    // ---------------------------------------------------------------------

    /** Create a custom tag. Returns the tag ({id, label, ...}). 409 if the label exists. */
    public function createCustomTag(string $label, ?string $description = null): array
    {
        return $this->write('post', '/custom-tags', array_filter(['label' => $label, 'description' => $description], fn ($v) => $v !== null));
    }

    /** Find a tag by label (case-insensitive), creating it if missing. Returns the tag id. */
    public function ensureCustomTag(string $label, ?string $description = null): string
    {
        foreach ($this->listCustomTags() as $tag) {
            if (strcasecmp(trim((string) ($tag['label'] ?? '')), trim($label)) === 0) {
                return (string) $tag['id'];
            }
        }

        return (string) ($this->createCustomTag($label, $description)['id'] ?? '');
    }

    /**
     * Assign/unassign tags on email accounts (resource_type 1 = account).
     * $resourceIds are account identifiers as Instantly returns them (see
     * InstantlySyncService::accountRef — the account id when present, else email).
     *
     * @param  array<int,string>  $tagIds
     * @param  array<int,string>  $resourceIds
     */
    public function toggleAccountTags(array $tagIds, array $resourceIds, bool $assign): void
    {
        foreach (array_chunk(array_values($resourceIds), 100) as $chunk) {
            $this->write('post', '/custom-tags/toggle-resource', [
                'tag_ids' => array_values($tagIds),
                'resource_type' => 1,
                'resource_ids' => $chunk,
                'assign' => $assign,
            ]);
        }
    }

    /** Full campaign (includes email_list = the sending accounts). */
    public function getCampaign(string $id): array
    {
        return $this->get('/campaigns/'.rawurlencode($id));
    }

    /**
     * Set a campaign's sending selection: explicit mailboxes + tags.
     *
     * @param  array{email_list: array, email_tag_list: array}  $selection
     */
    public function setCampaignSending(string $id, array $selection): array
    {
        return $this->write('patch', '/campaigns/'.rawurlencode($id), [
            'email_list' => array_values(array_unique($selection['email_list'])),
            'email_tag_list' => array_values(array_unique($selection['email_tag_list'])),
        ]);
    }

    /** Replace a campaign's sending accounts. */
    public function setCampaignSendingAccounts(string $id, array $emails): array
    {
        return $this->write('patch', '/campaigns/'.rawurlencode($id), ['email_list' => array_values(array_unique($emails))]);
    }

    /**
     * Issue a write (POST/PATCH) and normalise error statuses the same way get()
     * does, so the service layer can surface a clear message (esp. 403 = the key
     * lacks the accounts:update scope).
     *
     * @param  array<string,mixed>  $body
     */
    private function write(string $method, string $path, array $body = []): array
    {
        $req = $this->http();
        $res = $method === 'patch'
            ? $req->patch($this->baseUrl.$path, $body)
            : $req->post($this->baseUrl.$path, $body);

        if ($res->status() === 401) {
            throw new RuntimeException('Instantly: invalid or missing API key (401).');
        }
        if ($res->status() === 403) {
            throw new RuntimeException('Instantly: this API key lacks write scope (403). Regenerate the key in Instantly with the accounts:update (or all:all) scope and reconnect the workspace.');
        }
        if ($res->status() === 404) {
            throw new RuntimeException('Instantly: resource not found (404) — '.$path);
        }
        if ($res->status() === 409) {
            throw new RuntimeException('Instantly: already exists (409).');
        }
        if ($res->status() === 429) {
            throw new RuntimeException('Instantly: rate limit exceeded (429). Try again shortly.');
        }
        if ($res->failed()) {
            throw new RuntimeException('Instantly API error ('.$res->status().'): '.$res->body());
        }

        return $res->json() ?? [];
    }
}
