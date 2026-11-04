<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchProblem;
use App\Services\AuditLogger;
use App\Services\ProblemRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProblemController extends Controller
{
    public function __construct(
        private ProblemRecorder $recorder,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = BatchProblem::with(['batch:id,batch_id', 'workspace:id,name,code']);

        if ($s = $request->query('status')) {
            $s === 'open'
                ? $query->whereIn('status', BatchProblem::OPEN_STATES)
                : $query->where('status', $s);
        }
        if ($b = $request->query('batch_id')) {
            $query->where('batch_id', $b);
        }
        if ($sev = $request->query('severity')) {
            $query->where('severity', $sev);
        }

        return response()->json(
            $query->orderByDesc('detected_at')->paginate(min((int) $request->query('per_page', 25), 200))
        );
    }

    public function show(BatchProblem $problem): JsonResponse
    {
        $problem->load(['batch.workspace', 'recommendations', 'assignedOperator:id,name']);

        return response()->json(['data' => $problem]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            'batch_id' => ['nullable', 'uuid', 'exists:batches,id'],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'symptom' => ['required', 'string'],
            'evidence' => ['nullable', 'string'],
            'accounts_affected' => ['nullable', 'integer', 'min:0'],
            'approval_required' => ['boolean'],
        ]);

        $problem = BatchProblem::create(array_merge($data, [
            'problem_code' => $this->nextCode(),
            'status' => 'open',
            'root_cause_status' => 'unknown',
            'detected_at' => now(),
        ]));

        $this->audit->log('problem.created', $problem);

        return response()->json(['data' => $problem], 201);
    }

    public function update(Request $request, BatchProblem $problem): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'investigating', 'in_progress', 'waiting_approval', 'waiting_provider', 'resolved', 'closed'])],
            'severity' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'root_cause_status' => ['sometimes', Rule::in(['confirmed', 'suspected', 'unknown'])],
            'root_cause' => ['sometimes', 'nullable', 'string'],
            'impact' => ['sometimes', 'nullable', 'string'],
            'recommended_action' => ['sometimes', 'nullable', 'string'],
            'action_taken' => ['sometimes', 'nullable', 'string'],
            'assigned_operator_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'retest_date' => ['sometimes', 'nullable', 'date'],
        ]);

        if (($data['status'] ?? null) === 'resolved' && ! $problem->resolved_at) {
            $data['resolved_at'] = now();
        }

        $problem->update($data);
        $this->audit->log('problem.updated', $problem, $data);

        return response()->json(['data' => $problem->fresh()]);
    }

    /**
     * Run the detection rules engine for a batch and persist any NEW findings as
     * batch_problems (deduplicated by open rule_key), each with recommendations.
     * This records internal incidents only — it never touches external platforms.
     */
    public function detect(Batch $batch): JsonResponse
    {
        $result = $this->recorder->record($batch);
        $findings = $result['findings'];
        $created = $result['created'];

        $this->audit->log('problem.detection_run', $batch, [
            'created' => count($created),
            'resolved' => $result['resolved'],
            'findings' => count($findings),
        ]);

        return response()->json([
            'findings' => $findings,
            'created' => $created,
            'resolved' => $result['resolved'],
            'message' => count($created).' new problem(s) recorded from '.count($findings).' finding(s).'
                .($result['resolved'] ? " {$result['resolved']} cleared problem(s) auto-resolved." : ''),
        ]);
    }

    private function nextCode(): string
    {
        $n = BatchProblem::withTrashed()->count() + 1;

        return 'PRB-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
