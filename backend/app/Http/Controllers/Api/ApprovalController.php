<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Approval::with(['workspace:id,name,code', 'batch:id,batch_id', 'requestedBy:id,name', 'approvedBy:id,name']);
        if ($s = $request->query('status')) {
            $query->where('status', $s);
        }

        return response()->json($query->orderByDesc('requested_at')->paginate(min((int) $request->query('per_page', 25), 200)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action_type' => ['required', Rule::in([
                'pause_campaign', 'reconnect_account', 'delete_account', 'change_limit',
                'modify_warmup', 'replace_domain', 'purchase_plan', 'other',
            ])],
            'proposed_action' => ['required', 'string'],
            'evidence' => ['nullable', 'string'],
            'workspace_id' => ['nullable', 'uuid', 'exists:workspaces,id'],
            'batch_id' => ['nullable', 'uuid', 'exists:batches,id'],
            'accounts_affected' => ['nullable', 'integer', 'min:0'],
            'risk_description' => ['nullable', 'string'],
        ]);

        $approval = Approval::create(array_merge($data, [
            'requested_by_user_id' => $request->user()->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]));

        $this->audit->log('approval.requested', $approval, ['action_type' => $data['action_type']]);

        return response()->json(['data' => $approval], 201);
    }

    public function approve(Request $request, Approval $approval): JsonResponse
    {
        return $this->decide($request, $approval, 'approved');
    }

    public function reject(Request $request, Approval $approval): JsonResponse
    {
        return $this->decide($request, $approval, 'rejected');
    }

    /**
     * Records that an approved action was carried out MANUALLY by an operator.
     * The platform performs no external change itself; this only stores the log.
     */
    public function execute(Request $request, Approval $approval): JsonResponse
    {
        if ($approval->status !== 'approved') {
            return response()->json(['message' => 'Only approved actions can be marked executed.'], 422);
        }
        $data = $request->validate(['execution_log' => ['required', 'string']]);

        $approval->update([
            'status' => 'executed',
            'execution_log' => $data['execution_log'],
            'executed_at' => now(),
        ]);
        $this->audit->log('approval.executed', $approval);

        return response()->json(['data' => $approval->fresh()]);
    }

    private function decide(Request $request, Approval $approval, string $status): JsonResponse
    {
        if ($approval->status !== 'pending') {
            return response()->json(['message' => 'This request has already been decided.'], 422);
        }

        $approval->update([
            'status' => $status,
            'approved_by_user_id' => $request->user()->id,
            'decided_at' => now(),
        ]);
        $this->audit->log("approval.{$status}", $approval);

        return response()->json(['data' => $approval->fresh(['requestedBy', 'approvedBy'])]);
    }
}
