<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\IntegrationConnection;
use App\Services\DeliverabilityRefreshService;
use App\Services\ProblemRecorder;
use Illuminate\Console\Command;

/**
 * Scheduled hourly (routes/console.php): sync every connected Instantly
 * workspace, map mailboxes to batches, pull daily sending analytics, then run
 * problem detection across ALL batches (including batches in workspaces that
 * are not connected, so placement / lifecycle rules still fire).
 */
class DeliverabilityRefresh extends Command
{
    protected $signature = 'deliverability:refresh
        {workspace? : Optional workspace code to limit the refresh}
        {--no-activity : Skip the per-account daily analytics pull}';

    protected $description = 'Sync Instantly, map accounts to batches, pull daily activity and run problem detection.';

    public function handle(DeliverabilityRefreshService $refresh, ProblemRecorder $problems): int
    {
        $query = IntegrationConnection::where('provider', 'instantly')->whereNotNull('credentials')->with('workspace');
        if ($code = $this->argument('workspace')) {
            $query->whereHas('workspace', fn ($q) => $q->where('code', $code));
        }

        $refreshedWorkspaces = [];
        foreach ($query->get() as $conn) {
            $name = $conn->workspace?->name ?? $conn->workspace_id;
            try {
                $r = $refresh->refresh($conn, ! $this->option('no-activity'));
                $this->info("{$name}: {$r['message']}");
                $refreshedWorkspaces[] = $conn->workspace_id;
            } catch (\Throwable $e) {
                $this->error("{$name}: refresh failed — {$e->getMessage()}");
            }
        }

        // Detection for batches whose workspace was not refreshed above.
        if (! $this->argument('workspace')) {
            $others = Batch::with('workspace')->whereNotIn('workspace_id', $refreshedWorkspaces ?: ['__none__'])->get();
            foreach ($others as $batch) {
                $problems->record($batch);
            }
        }

        return self::SUCCESS;
    }
}
