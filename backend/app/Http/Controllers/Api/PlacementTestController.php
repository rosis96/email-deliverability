<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlacementTest;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlacementTestController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = PlacementTest::with(['workspace:id,name,code', 'batch:id,batch_id']);
        if ($w = $request->query('workspace_id')) {
            $query->where('workspace_id', $w);
        }
        if ($b = $request->query('batch_id')) {
            $query->where('batch_id', $b);
        }

        return response()->json(['data' => $query->orderByDesc('test_date')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            // Null batch_id => workspace-level. We NEVER auto-map to batches.
            'batch_id' => ['nullable', 'uuid', 'exists:batches,id'],
            'test_date' => ['required', 'date'],
            'tested_domains' => ['nullable', 'string'],
            'provider' => ['nullable', 'string', 'max:64'],
            'inbox_pct' => ['nullable', 'numeric', 'between:0,100'],
            'spam_pct' => ['nullable', 'numeric', 'between:0,100'],
            'missing_pct' => ['nullable', 'numeric', 'between:0,100'],
            'sample_size' => ['nullable', 'integer', 'min:0'],
            'source' => ['nullable', 'string', 'max:128'],
            'evidence' => ['nullable', 'string'],
        ]);

        $data['scope'] = empty($data['batch_id']) ? 'workspace' : 'batch';
        $test = PlacementTest::create($data);
        $this->audit->log('placement_test.created', $test, ['scope' => $data['scope']]);

        return response()->json(['data' => $test], 201);
    }
}
