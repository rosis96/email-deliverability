<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\Workspace;
use App\Services\Integrations\InstantlySyncService;
use Illuminate\Console\Command;

class InstantlyConnect extends Command
{
    protected $signature = 'instantly:connect {workspace : Workspace code (e.g. REV)} {key : v2 API key} {--no-sync : Verify only, do not pull data}';

    protected $description = 'Store an Instantly v2 API key for a workspace, verify it, and sync (read-only).';

    public function handle(InstantlySyncService $svc): int
    {
        $workspace = Workspace::where('code', $this->argument('workspace'))->first();
        if (! $workspace) {
            $this->error("Unknown workspace code: {$this->argument('workspace')}");

            return self::FAILURE;
        }

        $conn = IntegrationConnection::firstOrNew(['provider' => 'instantly', 'workspace_id' => $workspace->id]);
        $conn->credentials = $this->argument('key');
        $conn->status = 'not_configured';
        $conn->save();

        try {
            $ws = $svc->verify($conn);
            $this->info("Connected to Instantly workspace: ".($ws['name'] ?? 'unknown')." (id ".($ws['id'] ?? '?').")");

            if (! $this->option('no-sync')) {
                $log = $svc->sync($conn);
                $this->info('Sync: '.$log->message);
            }
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
