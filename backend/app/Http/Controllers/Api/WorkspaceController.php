<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Workspace;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class WorkspaceController extends Controller
{
    public function __construct(private DashboardService $dashboard) {}

    public function index(): JsonResponse
    {
        $workspaces = Workspace::withCount('batches')->orderBy('name')->get();

        return response()->json([
            'data' => $workspaces->map(fn (Workspace $w) => $this->summary($w)),
        ]);
    }

    public function show(Workspace $workspace): JsonResponse
    {
        $batches = $workspace->batches()->with('workspace')->withCount('domains')->get();

        return response()->json([
            'workspace' => $this->summary($workspace),
            'batches' => $batches->map(fn (Batch $b) => $this->dashboard->batchRow($b))->values(),
            'placement_tests' => $workspace->placementTests()->orderByDesc('test_date')->get(),
        ]);
    }

    private function summary(Workspace $w): array
    {
        $counts = $w->counts_toward_totals
            ? [
                'total_accounts' => (int) $w->batches()->sum('actual_account_count'),
                'active_accounts' => (int) $w->batches()->sum('active_account_count'),
                'error_accounts' => (int) $w->batches()->sum('error_account_count'),
            ]
            : [
                // Never fabricate zero for expired/unverified workspaces.
                'total_accounts' => null,
                'active_accounts' => null,
                'error_accounts' => null,
            ];

        return [
            'id' => $w->id,
            'name' => $w->name,
            'code' => $w->code,
            'external_instantly_id' => $w->external_instantly_id,
            'status' => $w->status,
            'status_display' => $w->isExpired()
                ? ($w->status === 'expired' ? 'Not verified — expired plan blocks access' : 'Not verified')
                : ucfirst($w->status),
            'counts_toward_totals' => $w->counts_toward_totals,
            'integration_status' => $w->integration_status,
            'last_synced_at' => $w->last_synced_at,
            'batches_count' => $w->batches_count ?? $w->batches()->count(),
            'provider_distribution' => $w->batches()
                ->selectRaw('provider_type, count(*) as c')
                ->groupBy('provider_type')
                ->pluck('c', 'provider_type'),
        ] + $counts;
    }
}
