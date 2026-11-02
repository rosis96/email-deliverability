<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\DashboardService;
use App\Services\RotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboard,
        private RotationService $rotation,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json($this->dashboard->overview());
    }

    /**
     * Batch dashboard table (one row per batch) with filters, global search,
     * sorting and pagination.
     */
    public function batches(Request $request): JsonResponse
    {
        $query = Batch::query()->with('workspace')->withCount('domains');

        // --- Filters ---
        if ($ws = $request->query('workspace')) {
            $query->whereHas('workspace', fn ($q) => $q->where('code', $ws)->orWhere('id', $ws));
        }
        if ($provider = $request->query('provider')) {
            $query->where('provider_type', $provider);
        }
        if ($phase = $request->query('phase')) {
            $query->where('phase', $phase);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // --- Global search (batch id, workspace, domain, email) ---
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('batch_id', 'ilike', "%{$search}%")
                    ->orWhereHas('workspace', fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"))
                    ->orWhereHas('domains', fn ($d) => $d->where('name', 'ilike', "%{$search}%"))
                    ->orWhereHas('emailAccounts', fn ($e) => $e->where('email', 'ilike', "%{$search}%"));
            });
        }

        $rows = $query->get()->map(fn (Batch $b) => $this->dashboard->batchRow($b));

        // Health / rotation / problem filters operate on derived values.
        if ($request->boolean('rotation_only')) {
            $rows = $rows->where('rotation_flag', true);
        }
        if ($request->boolean('problems_only')) {
            $rows = $rows->where('open_problems', '>', 0);
        }
        if ($health = $request->query('health')) {
            $rows = $rows->where('inbox_placement', $health);
        }

        // --- Sorting (derived-value aware) ---
        $sort = $request->query('sort', 'batch_id');
        $dir = $request->query('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $allowed = ['batch_id', 'error_pct', 'total_accounts', 'utilisation', 'bounce_rate', 'replacement_due', 'active_accounts'];
        if (in_array($sort, $allowed, true)) {
            $rows = $dir === 'desc'
                ? $rows->sortByDesc($sort, SORT_REGULAR)
                : $rows->sortBy($sort, SORT_REGULAR);
        }
        $rows = $rows->values();

        // --- Pagination ---
        $perPage = min((int) $request->query('per_page', 25), 200);
        $page = max((int) $request->query('page', 1), 1);
        $total = $rows->count();

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil(max($total, 1) / $perPage),
            ],
        ]);
    }
}
