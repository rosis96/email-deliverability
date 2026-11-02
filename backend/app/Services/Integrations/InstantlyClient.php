<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for the Instantly.ai v2 API (https://api.instantly.ai).
 *
 * This client issues GET requests ONLY. The platform never writes to Instantly.
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
            $query = ['limit' => 100];
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
}
