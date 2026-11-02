<?php

namespace Database\Seeders;

use App\Models\IntegrationConnection;
use Illuminate\Database\Seeder;

class IntegrationSeeder extends Seeder
{
    public function run(): void
    {
        // Adapters exist but are not connected until credentials are configured
        // server-side. We never fake a successful integration.
        foreach (['instantly', 'maildoso', 'postmaster', 'snds', 'mxtoolbox'] as $provider) {
            IntegrationConnection::firstOrCreate(
                ['provider' => $provider, 'workspace_id' => null],
                ['status' => 'not_configured', 'config' => null],
            );
        }
    }
}
