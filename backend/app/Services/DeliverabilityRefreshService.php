<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use App\Services\Integrations\AccountMappingService;
use App\Services\Integrations\InstantlySyncService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One full refresh of a connected Instantly workspace:
 *   1. sync accounts + campaigns from Instantly (read-only)
 *   2. map mailboxes to batches and recompute live batch counts
 *   3. pull per-account daily sending analytics (sent / replies / bounces)
 *   4. run the detection engine and record / auto-resolve batch problems
 *
 * Steps 2–4 used to exist only as manual CLI commands (or not at all), so the
 * dashboard kept showing the Oct-30 audit snapshot no matter how often you synced.
 */
class DeliverabilityRefreshService
{
    public function __construct(
        private InstantlySyncService $sync,
        private AccountMappingService $mapping,
        private ProblemRecorder $problems,
    ) {}

    /**
     * @return array{sync: string, mapping: array, activity: int|string|null, problems: array, message: string}
     *
     * @throws Throwable when the Instantly sync itself fails (bad key, outage)
     */
    public function refresh(IntegrationConnection $conn, bool $withActivity = true): array
    {
        $log = $this->sync->sync($conn);
        $workspace = $conn->fresh('workspace')->workspace;

        $mapping = $workspace ? $this->mapping->mapWorkspace($workspace) : ['mapped' => 0];

        $activity = null;
        if ($withActivity && $workspace) {
            try {
                $activity = $this->mapping->pullDailyActivity($conn);
            } catch (Throwable $e) {
                // Analytics are secondary — never fail the whole refresh for them.
                Log::warning('Instantly daily analytics pull failed', ['workspace' => $workspace->code, 'error' => $e->getMessage()]);
                $activity = 'failed: '.$e->getMessage();
            }
        }

        $problems = $workspace ? $this->problems->recordWorkspace($workspace) : ['created' => 0, 'resolved' => 0];

        $message = $log->message
            .sprintf(' Mapped %d mailbox(es) to batches', $mapping['mapped'] ?? 0)
            .(! empty($mapping['removed']) ? ", removed {$mapping['removed']} no longer in Instantly" : '')
            .'.'
            .(is_int($activity) ? " {$activity} daily activity row(s) updated." : '')
            .($problems['created'] ? " {$problems['created']} new problem(s) detected." : '')
            .($problems['resolved'] ? " {$problems['resolved']} problem(s) auto-resolved." : '');

        return [
            'sync' => $log->message,
            'mapping' => $mapping,
            'activity' => $activity,
            'problems' => $problems,
            'message' => $message,
        ];
    }
}
