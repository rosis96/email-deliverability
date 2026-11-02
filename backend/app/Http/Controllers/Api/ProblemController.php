<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchProblem;
use App\Models\Recommendation;
use App\Services\AuditLogger;
use App\Services\ProblemDetectionService;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProblemController extends Controller
{
    public function __construct(
        private ProblemDetectionService $detection,
        private RecommendationService $recommendations,
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
        $findings = $this->detection->evaluate($batch);
        $created = [];

        DB::transaction(function () use ($batch, $findings, &$created) {
            foreach ($findings as $finding) {
                $exists = BatchProblem::where('batch_id', $batch->id)
                    ->where('rule_key', $finding['rule_key'])
                    ->whereIn('status', BatchProblem::OPEN_STATES)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $problem = BatchProblem::create([
                    'problem_code' => $this->nextCode(),
                    'workspace_id' => $batch->workspace_id,
                    'batch_id' => $batch->id,
                    'rule_key' => $finding['rule_key'],
                    'accounts_affected' => $finding['accounts_affected'],
                    'severity' => $finding['severity'],
                    'symptom' => $finding['symptom'],
                    'evidence' => $finding['evidence'],
                    'root_cause_status' => 'unknown',
                    'approval_required' => $finding['approval_required'],
                    'status' => 'open',
                    'detected_at' => now(),
                ]);

                $rec = $this->recommendations->fromFinding($finding);
                Recommendation::create(array_merge($rec, [
                    'batch_problem_id' => $problem->id,
                    'batch_id' => $batch->id,
                    'workspace_id' => $batch->workspace_id,
                    'status' => 'suggested',
                ]));

                $created[] = $problem;
            }
        });

        $this->audit->log('problem.detection_run', $batch, ['created' => count($created), 'findings' => count($findings)]);

        return response()->json([
            'findings' => $findings,
            'created' => $created,
            'message' => count($created).' new problem(s) recorded from '.count($findings).' finding(s).',
        ]);
    }

    private function nextCode(): string
    {
        $n = BatchProblem::withTrashed()->count() + 1;

        return 'PRB-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
