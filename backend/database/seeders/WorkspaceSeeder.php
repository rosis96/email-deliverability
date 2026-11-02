<?php

namespace Database\Seeders;

use App\Models\Workspace;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $workspaces = [
            ['name' => 'New Edge', 'code' => 'NE', 'status' => 'active', 'counts' => true],
            ['name' => 'Unicorn Marketers', 'code' => 'UNI', 'status' => 'active', 'counts' => true],
            ['name' => 'Revcadence', 'code' => 'REV', 'status' => 'active', 'counts' => true],
            ['name' => 'Firebelly Creative', 'code' => 'FB', 'status' => 'active', 'counts' => true],
            ['name' => 'Big Smile', 'code' => 'BS', 'status' => 'active', 'counts' => true],
            ['name' => 'Future works', 'code' => 'FW', 'status' => 'active', 'counts' => true],
            ['name' => 'Resonance', 'code' => 'RES', 'status' => 'active', 'counts' => true],
            // Expired — totals are UNKNOWN and must never be reported as zero.
            ['name' => 'My Organization Free Trial', 'code' => 'FREE', 'status' => 'expired', 'counts' => false],
        ];

        foreach ($workspaces as $w) {
            Workspace::updateOrCreate(
                ['code' => $w['code']],
                [
                    'name' => $w['name'],
                    'status' => $w['status'],
                    'counts_toward_totals' => $w['counts'],
                    'integration_status' => 'not_configured',
                    'notes' => $w['status'] === 'expired'
                        ? 'Expired plan blocks access. Account totals unknown — not verified.'
                        : null,
                ],
            );
        }
    }
}
