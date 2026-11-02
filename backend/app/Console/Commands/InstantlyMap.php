<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\Integrations\AccountMappingService;
use Illuminate\Console\Command;

class InstantlyMap extends Command
{
    protected $signature = 'instantly:map {workspace? : Optional workspace code} {--activity : Also pull per-account daily sending analytics}';

    protected $description = 'Map synced Instantly accounts to batches (by provider) and recompute live counts.';

    public function handle(AccountMappingService $svc): int
    {
        $query = IntegrationConnection::where('provider', 'instantly')->whereNotNull('credentials');
        if ($code = $this->argument('workspace')) {
            $query->whereHas('workspace', fn ($q) => $q->where('code', $code));
        }
        $connections = $query->with('workspace')->get();

        if ($connections->isEmpty()) {
            $this->warn('No connected Instantly workspaces.');

            return self::SUCCESS;
        }

        foreach ($connections as $conn) {
            $ws = $conn->workspace;
            if (! $ws) {
                continue;
            }
            $r = $svc->mapWorkspace($ws);
            $this->info("{$ws->code}: mapped {$r['mapped']} accounts into {$r['batches']} batch(es), skipped {$r['skipped']}.");

            if ($this->option('activity')) {
                try {
                    $written = $svc->pullDailyActivity($conn);
                    $this->line("  daily activity rows written: {$written}");
                } catch (\Throwable $e) {
                    $this->error('  activity pull failed: '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }
}
