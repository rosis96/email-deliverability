<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\RotationPlan;
use App\Services\AuditLogger;
use App\Services\LifecycleService;
use App\Services\RotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RotationController extends Controller
{
    public function __construct(
        private RotationService $rotation,
        private LifecycleService $lifecycle,
        private AuditLogger $audit,
    ) {}

    /** Batches currently flagged as rotation candidates, with reasons. */
    public function candidates(): JsonResponse
    {
        $out = [];
        foreach (Batch::with('workspace')->where('phase', '!=', 'retired')->get() as $batch) {
            $reasons = $this->rotation->candidateReasons($batch);
            if (! empty($reasons)) {
                $out[] = [
                    'batch_id' => $batch->batch_id,
                    'id' => $batch->id,
                    'workspace' => $batch->workspace?->name,
                    'phase' => $batch->phase,
                    'error_pct' => $batch->errorPercentage(),
                    'replacement_due' => $this->lifecycle->replacementDue($batch)?->toDateString(),
                    'reasons' => $reasons,
                ];
            }
        }

        return response()->json(['data' => $out]);
    }

    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => RotationPlan::with(['currentBatch:id,batch_id', 'replacementBatch:id,batch_id', 'workspace:id,name'])
                ->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_batch_id' => ['required', 'uuid', 'exists:batches,id'],
            'replacement_batch_id' => ['nullable', 'uuid', 'exists:batches,id', 'different:current_batch_id'],
            'workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            'overlap_start' => ['nullable', 'date'],
            'overlap_end' => ['nullable', 'date', 'after_or_equal:overlap_start'],
            'retirement_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $plan = RotationPlan::create(array_merge($data, ['status' => 'identified']));
        $this->audit->log('rotation.plan_created', $plan);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, RotationPlan $rotationPlan): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in([
                'identified', 'replacement_created', 'infra_verified', 'warmup',
                'placement_validated', 'approved', 'overlapping', 'transitioning', 'retired',
            ])],
            'replacement_batch_id' => ['sometimes', 'nullable', 'uuid', 'exists:batches,id'],
            'overlap_start' => ['sometimes', 'nullable', 'date'],
            'overlap_end' => ['sometimes', 'nullable', 'date'],
            'retirement_date' => ['sometimes', 'nullable', 'date'],
            'transition_status' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $rotationPlan->update($data);
        $this->audit->log('rotation.plan_updated', $rotationPlan, $data);

        return response()->json(['data' => $rotationPlan->fresh()]);
    }
}
