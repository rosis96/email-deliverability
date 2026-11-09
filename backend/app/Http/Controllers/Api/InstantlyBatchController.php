<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchOperation;
use App\Models\ImportedRecord;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Integrations\InstantlyBatchService;
use App\Services\LifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Batch-level control of Instantly: mailbox lists, unassigned mailboxes,
 * previews, and queued operations (tag sync, assign, stage, rotate).
 */
class InstantlyBatchController extends Controller
{
    public function __construct(
        private InstantlyBatchService $service,
        private LifecycleService $lifecycle,
        private AuditLogger $audit,
    ) {}

    /** Mailboxes currently in a batch (from the latest sync). */
    public function mailboxes(Batch $batch): JsonResponse
    {
        $rows = ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->where('batch_id', $batch->id)->orderBy('external_record_id')->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->mailboxRow($r))->values()]);
    }

    /** Mailboxes in a workspace that could not be matched to a batch. */
    public function unassigned(Workspace $workspace): JsonResponse
    {
        $rows = ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->where('workspace_id', $workspace->id)->whereNull('batch_id')
            ->orderBy('external_record_id')->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->mailboxRow($r))->values(),
            'batches' => $workspace->batches()->orderBy('batch_id')->get(['id', 'batch_id', 'provider_type', 'phase']),
        ]);
    }

    /** Unassigned counts per workspace (for the badge on Batch Management). */
    public function unassignedSummary(): JsonResponse
    {
        $counts = ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->whereNull('batch_id')->whereNotNull('workspace_id')
            ->selectRaw('workspace_id, count(*) as c')->groupBy('workspace_id')->pluck('c', 'workspace_id');
        $ws = Workspace::whereIn('id', $counts->keys())->get(['id', 'name', 'code']);

        return response()->json(['data' => $ws->map(fn ($w) => [
            'workspace_id' => $w->id, 'name' => $w->name, 'code' => $w->code, 'count' => (int) $counts[$w->id],
        ])->values()]);
    }

    public function operations(Batch $batch): JsonResponse
    {
        return response()->json(['data' => BatchOperation::where('batch_id', $batch->id)
            ->with('user:id,name')->orderByDesc('created_at')->limit(30)->get()]);
    }

    public function operation(BatchOperation $operation): JsonResponse
    {
        return response()->json(['data' => $operation->load('user:id,name', 'batch:id,batch_id')]);
    }

    /** Read-only: exactly what an operation would change in Instantly. */
    public function preview(Request $request, Batch $batch): JsonResponse
    {
        $data = $this->validated($request, $batch);

        try {
            $preview = match ($data['type']) {
                'stage' => $this->service->previewStage($batch->load('workspace'), $data['phase']),
                'rotate' => $this->service->previewRotation($batch->load('workspace'), Batch::findOrFail($data['to_batch_id'])),
                'tag_sync' => [
                    'actions' => ["Create tag \"{$batch->batch_id}\" in Instantly (if missing) and add it to every mailbox in this batch"],
                    'accounts' => ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')->where('batch_id', $batch->id)->count(),
                    'campaigns' => [], 'warnings' => [],
                ],
            };
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $preview]);
    }

    /** Queue an operation; it runs right after this response is sent. */
    public function apply(Request $request, Batch $batch): JsonResponse
    {
        $data = $this->validated($request, $batch);

        if ($data['type'] === 'stage' && ($guard = $this->stageGuard($batch, $data))) {
            return response()->json(['message' => $guard], 422);
        }
        if ($data['type'] === 'stage' && $data['phase'] === 'active' && ! empty($data['active_start']) && ! $batch->active_start) {
            $batch->update(['active_start' => $data['active_start']]);
        }

        $op = $this->service->queue($batch, $data['type'], array_filter([
            'phase' => $data['phase'] ?? null,
            'to_batch_id' => $data['to_batch_id'] ?? null,
        ]), $request->user()?->id);

        $this->audit->log('instantly.batch_operation.queued', $batch, ['operation' => $op->id, 'type' => $op->type, 'params' => $op->params]);
        $this->dispatchRun($op);

        return response()->json(['message' => 'Started — progress is shown below.', 'data' => $op], 202);
    }

    /** Move mailboxes into a batch (app immediately; Instantly tags in background). */
    public function assign(Request $request, Batch $batch): JsonResponse
    {
        $data = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:2000'],
            'emails.*' => ['required', 'email'],
        ]);

        $op = $this->service->queue($batch, 'assign', ['emails' => array_values(array_unique($data['emails']))], $request->user()?->id);
        $this->audit->log('instantly.batch_operation.queued', $batch, ['operation' => $op->id, 'type' => 'assign', 'count' => count($data['emails'])]);
        $this->dispatchRun($op);

        return response()->json(['message' => count($data['emails'])." mailbox(es) assigned to {$batch->batch_id}; tagging in Instantly…", 'data' => $op], 202);
    }

    private function dispatchRun(BatchOperation $op): void
    {
        $service = $this->service;
        $id = $op->id;
        app()->terminating(function () use ($service, $id) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $op = BatchOperation::find($id);
            if ($op && $op->status === 'queued') {
                $service->run($op);
            }
        });
    }

    private function validated(Request $request, Batch $batch): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['stage', 'rotate', 'tag_sync'])],
            'phase' => ['required_if:type,stage', 'nullable', Rule::in(array_keys(InstantlyBatchService::STAGE_ACTIONS))],
            'to_batch_id' => ['required_if:type,rotate', 'nullable', 'uuid', 'exists:batches,id'],
            'active_start' => ['nullable', 'date'],
        ]);
        if (($data['to_batch_id'] ?? null) === $batch->id) {
            abort(response()->json(['message' => 'Choose a different batch to rotate to.'], 422));
        }

        return $data;
    }

    /** Same guardrails as a manual phase transition. */
    private function stageGuard(Batch $batch, array $data): ?string
    {
        if ($data['phase'] === 'ramp') {
            $warmup = $this->lifecycle->warmup($batch);
            if ($warmup['status'] !== 'complete' || ! $batch->readiness_checks_passed) {
                return 'Batch cannot enter ramp: warmup must be complete and readiness checks recorded.';
            }
        }
        if ($data['phase'] === 'active' && empty($batch->active_start) && empty($data['active_start'])) {
            return 'An active start date is required to move a batch to Active.';
        }

        return null;
    }

    private function mailboxRow(ImportedRecord $r): array
    {
        $p = $r->payload ?? [];

        return [
            'email' => $r->external_record_id,
            'status' => $p['status'] ?? null,
            'provider' => $p['provider'] ?? null,
            'daily_limit' => $p['daily_limit'] ?? null,
            'warmup_on' => ($p['warmup_status'] ?? null) === 1,
            'warmup_score' => $p['stat_warmup_score'] ?? null,
            'tags' => array_column($p['tags'] ?? [], 'label'),
            'confidence' => $r->batch_mapping_confidence,
            'synced_at' => $r->imported_at,
        ];
    }
}
