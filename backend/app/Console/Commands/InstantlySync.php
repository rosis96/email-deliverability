<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\Integrations\InstantlySyncService;
use Illuminate\Console\Command;

class InstantlySync extends Command
{
    protected $signature = 'instantly:sync {workspace? : Optional workspace code to limit the sync}';

    protected $description = 'Sync all connected Instantly workspaces (read-only). Scheduled hourly.';

    public function handle(InstantlySyncService $svc): int
    {
        $query = IntegrationConnection::where('provider', 'instantly')->whereNotNull('credentials');

        if ($code = $this->argument('workspace')) {
            $query->whereHas('workspace', fn ($q) => $q->where('code', $code));
        }

        $connections = $query->get();
        if ($connections->isEmpty()) {
            $this->warn('No connected Instantly workspaces to sync.');

            return self::SUCCESS;
        }

        foreach ($connections as $conn) {
            $this->line("Syncing workspace: ".($conn->workspace?->name ?? $conn->workspace_id));
            try {
                $log = $svc->sync($conn);
                $this->info('  '.$log->message);
            } catch (\Throwable $e) {
                $this->error('  Failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
